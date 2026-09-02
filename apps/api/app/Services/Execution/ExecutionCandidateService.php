<?php

namespace App\Services\Execution;

use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Models\Portfolio;
use App\Models\PositionRiskState;
use App\Models\Price;
use App\Models\TradingDay;
use App\Models\WatchlistScore;
use App\Services\Analysis\AssetTechnicalSnapshot;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use App\Services\Portfolio\PortfolioCalculator;
use App\Services\Strategy\BrokerFlowAnalyzer;
use App\Services\Strategy\BrokerFlowAssessment;
use App\Services\Strategy\BrokerRegime;
use App\Services\Strategy\BrokerWindowResolver;
use App\Services\Strategy\ExecutionScoreV2;
use App\Services\Strategy\OutcomeProbabilityService;
use App\Services\Strategy\PositionAction;
use App\Services\Strategy\RiskCalculator;
use App\Services\Strategy\SetupBucket;
use App\Services\Strategy\StrategyProfile;
use App\Services\Strategy\StrategyScoringService;
use App\Services\Strategy\TradingCostModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one list the user reads to decide what may be actionable next session.
 *
 * It composes rather than recomputes. Structural technicals come from
 * AssetTechnicalSnapshotService, the score and its sub-scores from the
 * watchlist pipeline that already produced them, the entry plan from
 * ExecutionPlanner. Nothing in here re-implements a moving average, a
 * risk/reward, or a scoring weight -- the point of the refactor was to remove
 * the second copies, not to add a third.
 *
 * Two ideas the interface previously blurred are kept apart:
 *
 *   structural_rank  how strong a stock is relative to the universe. Trend and
 *                    proximity to its own highs. Slow-moving.
 *   execution_rank   how actionable its current setup is for the next session.
 *                    Score, filters, and a plan that survives being measured
 *                    at the price you would actually pay.
 *
 * A stock can be structurally excellent and not executable, which is the
 * ordinary case and the reason one number could never serve both.
 */
class ExecutionCandidateService
{
    public function __construct(
        private readonly AssetTechnicalSnapshotService $snapshots,
        private readonly ExecutionPlanner $planner,
        private readonly RiskCalculator $risk,
        private readonly BrokerWindowResolver $windows,
        private readonly BrokerFlowAnalyzer $brokerFlow,
        private readonly ExecutionScoreV2 $scoreV2,
        private readonly OutcomeProbabilityService $probability,
        private readonly PositionLifecycleService $lifecycle,
    ) {}

    /**
     * Build the candidate list for a signal date.
     *
     * @param  array{
     *   date?: string|null,
     *   version?: string,
     *   symbols?: array<int, string>|null,
     *   sector?: string|null,
     *   statuses?: array<int, string>|null,
     *   min_score?: float|null,
     *   min_rr?: float|null,
     *   limit?: int|null,
     *   portfolio_id?: int|null,
     * }  $options
     * @return array<string, mixed>
     */
    public function candidates(array $options = []): array
    {
        $version = (string) ($options['version'] ?? StrategyScoringService::VERSION);
        $signalDate = $this->resolveSignalDate($options['date'] ?? null, $version);

        if ($signalDate === null) {
            return $this->empty($version, $options);
        }

        $scores = $this->loadScores($signalDate, $version, $options['symbols'] ?? null);

        if ($scores === []) {
            return $this->empty($version, $options, $signalDate);
        }

        $assets = Asset::query()
            ->whereIn('symbol', array_keys($scores))
            ->when(
                ($options['sector'] ?? null) !== null,
                fn ($query) => $query->where('sector', $options['sector'])
            )
            ->orderBy('symbol')
            ->get(['id', 'symbol', 'name', 'sector']);

        if ($assets->isEmpty()) {
            return $this->empty($version, $options, $signalDate);
        }

        $snapshotByAsset = $this->snapshots->snapshotsForAssetsAsOf($assets, $signalDate);
        $structuralRanks = $this->snapshots->structuralRanks($snapshotByAsset);
        $features = $this->loadFeatures(array_keys($scores), $signalDate);
        $previous = $this->loadPreviousScores($signalDate, $version, array_keys($scores));
        $streaks = $this->loadStatusStreaks($signalDate, $version, array_keys($scores));
        $holdings = $this->loadHoldings($options['portfolio_id'] ?? null);

        // One query for the whole page. Resolving the broker window per row
        // was four hundred round trips on a full universe.
        $brokerWindows = $this->windows->asOfMany(
            $assets->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $signalDate,
            (string) config('stockbit.defaults.transaction_type'),
        );

        $latestSession = $this->latestSessionDate();
        $nextTradingDate = $this->nextTradingDate($signalDate);
        $minScore = (float) ($options['min_score'] ?? config('execution.min_score', 75.0));
        $minRr = (float) ($options['min_rr'] ?? config('execution.min_rr', 2.0));
        $brokerLag = (int) config('execution.freshness.max_broker_lag_days', 5);

        $profile = StrategyProfile::fromConfig();
        $costs = new TradingCostModel($profile);
        $assetIds = $assets->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // The broker rollups the whole lifecycle rests on, in one query, and
        // bounded to windows that ended on or before the signal date.
        $flows = $this->loadBrokerFlows($assetIds, $signalDate, $profile, $features, $assets, $brokerWindows);

        // Plans first: the setup bucket needs the initial risk, and the risk
        // comes out of the plan. Two passes over the same assets rather than
        // one, so the probability lookup can be a single batched query.
        $plans = [];
        $buckets = [];

        foreach ($assets as $asset) {
            $snapshot = $snapshotByAsset[(int) $asset->id] ?? null;

            if ($snapshot === null) {
                continue;
            }

            $plan = $this->planner->planForProfile($snapshot, $profile);
            $plans[(int) $asset->id] = $plan;

            $buckets[(int) $asset->id] = SetupBucket::fromSnapshot(
                $snapshot,
                ($flows[(int) $asset->id] ?? null)?->regime ?? BrokerRegime::NEUTRAL,
                $plan['initial_risk_pct'] ?? null,
                $profile,
            );
        }

        // As-of, so scoring a historical date cannot consult outcomes that had
        // not happened by then. Look-ahead laundered through a statistic is
        // still look-ahead.
        $historical = $this->probability->forBuckets($buckets, $profile, $signalDate);

        // The next session's bar, when it exists, is what decides NO_CHASE:
        // the plan is for T+1, so whether price ran past the entry zone is a
        // question about T+1 and not about the signal session.
        $nextBars = $nextTradingDate === null ? [] : $this->loadBars($assetIds, $nextTradingDate);

        $lifecycleStates = $this->refreshLifecycle($options['portfolio_id'] ?? null, $profile, $signalDate, $flows);

        $rows = [];

        foreach ($assets as $asset) {
            $snapshot = $snapshotByAsset[(int) $asset->id] ?? null;
            $score = $scores[$asset->symbol] ?? null;

            if ($snapshot === null || $score === null) {
                continue;
            }

            $rows[] = $this->buildRow(
                asset: $asset,
                snapshot: $snapshot,
                score: $score,
                feature: $features[$asset->symbol] ?? null,
                structuralRank: $structuralRanks[$asset->symbol] ?? null,
                previous: $previous[$asset->symbol] ?? null,
                streak: $streaks[$asset->symbol] ?? null,
                holding: $holdings[(int) $asset->id] ?? null,
                brokerWindow: $brokerWindows[(int) $asset->id] ?? null,
                signalDate: $signalDate,
                latestSession: $latestSession,
                minScore: $minScore,
                minRr: $minRr,
                brokerLagDays: $brokerLag,
                profile: $profile,
                costs: $costs,
                flow: $flows[(int) $asset->id] ?? BrokerFlowAssessment::unavailable(),
                planV2: $plans[(int) $asset->id] ?? [],
                bucket: $buckets[(int) $asset->id] ?? null,
                historical: $historical[(int) $asset->id] ?? [],
                nextBar: $nextBars[(int) $asset->id] ?? null,
                riskState: $lifecycleStates[(int) $asset->id] ?? null,
                nextTradingDate: $nextTradingDate?->toDateString(),
            );
        }

        // Execution order: the v2 score, then the plan's risk/reward, then
        // symbol so the same inputs always produce the same list. The v1
        // score stays on the row as `execution_score`, and ranking on v2 is
        // the point of the version -- a model whose ordering nobody uses is
        // not a scoring model, it is a column.
        usort($rows, static function (array $a, array $b): int {
            $result = [$b['execution_score_v2'], $b['planned_risk_reward'] ?? -1]
                <=> [$a['execution_score_v2'], $a['planned_risk_reward'] ?? -1];

            return $result === 0 ? ($a['symbol'] <=> $b['symbol']) : $result;
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['execution_rank'] = $index + 1;
            $rows[$index]['execution_rank_change_1d'] = $row['previous_execution_rank'] === null
                ? null
                : $row['previous_execution_rank'] - ($index + 1);
        }

        $counts = $this->countStatuses($rows);

        $filtered = $this->applyFilters($rows, $options, $minScore, $minRr);
        $limit = max(1, (int) ($options['limit'] ?? config('execution.default_limit', 50)));

        return [
            'signal_date' => $signalDate->toDateString(),
            'next_trading_date' => $nextTradingDate?->toDateString(),
            'version' => $version,
            'thresholds' => [
                'min_score' => $minScore,
                'min_rr' => $minRr,
                'max_entry_gap_pct' => config('execution.max_entry_gap_pct'),
            ],
            'freshness' => $this->freshness($signalDate, $latestSession, $rows),
            'counts' => $counts,
            'total' => count($filtered),
            'rows' => array_slice($filtered, 0, $limit),
            'strategy_profile' => $profile->toArray(),
            'costs' => $costs->toArray(),
            'disclaimer' => (string) config('execution.disclaimer'),
            'outcome_disclaimer' => $profile->disclaimer,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $feature
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $streak
     * @param  array<string, mixed>|null  $holding
     * @param  array<string, mixed>  $planV2
     * @param  array<string, mixed>  $historical
     * @return array<string, mixed>
     */
    private function buildRow(
        Asset $asset,
        AssetTechnicalSnapshot $snapshot,
        WatchlistScore $score,
        ?object $feature,
        ?int $structuralRank,
        ?array $previous,
        ?array $streak,
        ?array $holding,
        ?BrokerSummaryWindow $brokerWindow,
        Carbon $signalDate,
        ?string $latestSession,
        float $minScore,
        float $minRr,
        int $brokerLagDays,
        StrategyProfile $profile,
        TradingCostModel $costs,
        BrokerFlowAssessment $flow,
        array $planV2,
        ?SetupBucket $bucket,
        array $historical,
        ?object $nextBar,
        ?PositionRiskState $riskState,
        ?string $nextTradingDate,
    ): array {
        // Levels are derived from the signal session only. The plan then
        // remeasures everything at the trigger.
        $riskBundle = $this->risk->compute(
            close: $snapshot->close,
            atr14: $snapshot->atr14,
            swingLow: $snapshot->swingLow20,
            high55: $snapshot->high55w,
        );

        $plan = $this->planner->plan($snapshot, $riskBundle['invalidation_level']);

        $validLongSetup = $feature === null ? null : (bool) $feature->valid_long_setup;
        $hardDistribution = $feature !== null && (bool) $feature->bandar_dist_hard;

        $status = $this->resolveStatus(
            snapshot: $snapshot,
            score: $score,
            plan: $plan,
            validLongSetup: $validLongSetup,
            hardDistribution: $hardDistribution,
            signalDate: $signalDate,
            latestSession: $latestSession,
            brokerWindowTo: $brokerWindow?->to_date?->toDateString(),
            brokerLagDays: $brokerLagDays,
            minScore: $minScore,
            minRr: $minRr,
        );

        $bavg = $brokerWindow?->bandarDetectorSummary?->average_price;
        $bavg = $bavg === null ? null : (float) $bavg;

        $lifecycle = $this->lifecycleBlocks(
            snapshot: $snapshot,
            flow: $flow,
            planV2: $planV2,
            bucket: $bucket,
            historical: $historical,
            nextBar: $nextBar,
            riskState: $riskState,
            holding: $holding,
            profile: $profile,
            costs: $costs,
            feature: $feature,
            signalDate: $signalDate,
            latestSession: $latestSession,
            nextTradingDate: $nextTradingDate,
            brokerWindowTo: $flow->windowEndDate ?? $brokerWindow?->to_date?->toDateString(),
        );

        return array_merge([
            'asset_id' => (int) $asset->id,
            'symbol' => (string) $asset->symbol,
            'name' => $asset->name,
            'sector' => $asset->sector,

            'signal_date' => $snapshot->asOfDate,
            'data_freshness' => [
                'price_date' => $snapshot->asOfDate,
                'feature_date' => $feature === null ? null : Carbon::parse((string) $feature->date)->toDateString(),
                'broker_window_from' => $brokerWindow?->from_date?->toDateString(),
                'broker_window_to' => $brokerWindow?->to_date?->toDateString(),
            ],

            // Filled in by the caller once the whole list is ordered.
            'execution_rank' => null,
            'execution_score' => (float) $score->score_total,
            'execution_status' => $status['status'],
            'status_reasons' => $status['reasons'],

            'structural_rank' => $structuralRank,
            'uptrend' => $snapshot->uptrend,
            'roc13' => $snapshot->roc13,
            'close_vs_high20' => $snapshot->closeVsHigh20,
            'close_vs_high55' => $snapshot->closeVsHigh55,
            'ma50' => $snapshot->ma50,
            'ma100' => $snapshot->ma100,
            'ma150' => $snapshot->ma150,
            'atr14' => $snapshot->atr14,

            'pbas' => $feature?->pbas === null ? null : (int) $feature->pbas,
            'bas' => (float) $score->score_bas,
            'bcs' => (float) $score->score_bcs,

            'liquidity_pass' => (bool) $score->lf_pass,
            'risk_reward_pass' => (bool) $score->rrf_pass,
            'valid_long_setup' => $validLongSetup,
            'hard_distribution' => $hardDistribution,

            'breakout20' => $snapshot->isBreakout20(),
            'close_pos' => $snapshot->closePos,
            'vol_ratio_20' => $snapshot->volRatio20,

            'signal_open' => $snapshot->open,
            'signal_high' => $snapshot->high,
            'signal_low' => $snapshot->low,
            'signal_close' => $snapshot->close,

            'bavg' => $bavg,
            'distance_from_bavg_pct' => ($bavg !== null && $bavg > 0)
                ? round((($snapshot->close - $bavg) / $bavg) * 100.0, 4)
                : null,

            'planned_entry_trigger' => $plan['entry_trigger'],
            'planned_entry_reason' => $plan['entry_reason'],
            'planned_stop' => $plan['stop'],
            'planned_target' => $plan['target'],
            'planned_risk_per_share' => $plan['risk_per_share'],
            'planned_risk_reward' => $plan['risk_reward'],

            // What the watchlist measured at the close, kept so the difference
            // between the two is visible rather than silently reconciled.
            'signal_close_risk_reward' => $riskBundle['risk_reward'],

            'top_brokers' => $score->top_brokers ?? [],
            'reasons' => $score->reasons ?? [],
            'risk_notes' => $score->risk_notes,
            'warnings' => array_merge($snapshot->warnings, $plan['notes']),

            'previous_execution_rank' => $previous['rank'] ?? null,
            'execution_rank_change_1d' => null,
            'execution_score_change_1d' => isset($previous['score'])
                ? round((float) $score->score_total - (float) $previous['score'], 4)
                : null,
            'ready_streak' => $streak['ready'] ?? 0,
            'watch_streak' => $streak['watch'] ?? 0,

            'holding' => $holding,
        ], $lifecycle);
    }

    /**
     * Everything the v2 lifecycle adds to a row.
     *
     * Grouped into blocks that answer one question each -- who is buying,
     * what the price did, where to get in, how the position would be managed,
     * how setups like this have turned out, and whether any of it is current
     * enough to act on. A flat row of forty columns is a lookup table; these
     * are the six things a decision actually needs.
     *
     * @param  array<string, mixed>  $planV2
     * @param  array<string, mixed>  $historical
     * @return array<string, mixed>
     */
    private function lifecycleBlocks(
        AssetTechnicalSnapshot $snapshot,
        BrokerFlowAssessment $flow,
        array $planV2,
        ?SetupBucket $bucket,
        array $historical,
        ?object $nextBar,
        ?PositionRiskState $riskState,
        ?array $holding,
        StrategyProfile $profile,
        TradingCostModel $costs,
        ?object $feature,
        Carbon $signalDate,
        ?string $latestSession,
        ?string $nextTradingDate,
        ?string $brokerWindowTo,
    ): array {
        $turnover = $feature === null ? null : (float) ($feature->turnover_value ?? 0);
        $activeBrokers = $feature === null ? null : (int) ($feature->active_broker_count ?? 0);

        $scored = $this->scoreV2->score($flow, $snapshot, $planV2, $historical, $turnover, $activeBrokers, $profile);

        // The price the entry zone is judged against: the next session's open
        // when it exists, otherwise the signal close as the best standing
        // estimate. Which one was used is reported rather than implied.
        $referencePrice = $nextBar?->open !== null ? (float) $nextBar->open : $snapshot->close;
        $referenceSource = $nextBar?->open !== null ? 'next_session_open' : 'signal_close';

        $zone = $this->planner->evaluateEntryZone($planV2, $referencePrice, $snapshot->atr14);

        $dataQuality = $this->dataQuality($snapshot, $signalDate, $latestSession, $nextTradingDate, $brokerWindowTo, $profile);

        $lifecycle = $this->resolveLifecycleStatus(
            snapshot: $snapshot,
            flow: $flow,
            plan: $planV2,
            zone: $zone,
            dataQuality: $dataQuality,
            riskState: $riskState,
            holding: $holding,
            profile: $profile,
        );

        $trigger = $planV2['trigger_price'] ?? null;

        return [
            'strategy_version' => $profile->version,
            'lifecycle_status' => $lifecycle['status'],
            'action' => $lifecycle['action'],
            'action_reasons' => $lifecycle['reasons'],

            'execution_score_v2' => $scored['score'],
            'score_components' => $scored['components'],
            'reasons_v2' => $scored['reasons'],

            'broker' => $flow->toArray(),

            'price_setup' => [
                'breakout20' => $snapshot->isBreakout20(),
                'breakout55' => $snapshot->isBreakout55(),
                'vol_ratio_20' => $snapshot->volRatio20,
                'close_position' => $snapshot->closePos,
                'atr14' => $snapshot->atr14,
                'ema20' => $snapshot->ema20,
                'ema50' => $snapshot->ema50,
                'ema_aligned' => $snapshot->emaAligned(),
                'above_ema20' => $snapshot->aboveEma20(),
                'prior_high20' => $snapshot->priorHigh20,
                'prior_high55' => $snapshot->priorHigh55,
                'distance_to_breakout_atr' => $snapshot->distanceToBreakoutAtr(),
                'distance_to_breakout_pct' => $snapshot->distanceToBreakoutPct(),
                'compression' => $snapshot->compression,
                'gap_pct' => $snapshot->gapPct,
                'swing_low20' => $snapshot->swingLow20,
            ],

            'execution_plan' => [
                'valid' => (bool) ($planV2['valid'] ?? false),
                'rejected_reason' => $planV2['rejected_reason'] ?? null,
                'breakout_level' => $planV2['breakout_level'] ?? null,
                'trigger_price' => $trigger,
                'entry_zone_low' => $planV2['entry_zone_low'] ?? null,
                'entry_zone_high' => $planV2['entry_zone_high'] ?? null,
                'entry_zone_atr' => $planV2['entry_zone_atr'] ?? null,
                'initial_stop' => $planV2['initial_stop'] ?? null,
                'initial_stop_source' => $planV2['initial_stop_source'] ?? null,
                'risk_per_share' => $planV2['risk_per_share'] ?? null,
                'initial_risk_pct' => $planV2['initial_risk_pct'] ?? null,
                'max_initial_risk_pct' => $planV2['max_initial_risk_pct'] ?? null,
                'reference_price' => round($referencePrice, 4),
                'reference_source' => $referenceSource,
                'entry_zone_state' => $zone['state'],
                'entry_zone_reason' => $zone['reason'],
                'extension_atr' => $zone['extension_atr'],
                'extension_pct' => $zone['extension_pct'],
                'notes' => $planV2['notes'] ?? [],
            ],

            'profit_management' => [
                'activation_gain_pct' => $profile->trailActivationGainPct,
                'activation_price' => $planV2['activation_price'] ?? null,
                'trailing_distance_pct' => $profile->trailingDistancePct,
                'minimum_locked_profit_pct' => $profile->minimumLockedProfitPct,
                'profit_floor_price' => $planV2['profit_floor_price'] ?? null,
                'round_trip_cost_pct' => $trigger === null ? null : $costs->roundTripCostPct((float) $trigger),
                'position' => $riskState === null ? null : $this->positionBlock($riskState, $snapshot, $profile),
            ],

            'historical_outcome' => $historical,

            'data_quality' => $dataQuality,

            'setup_bucket' => $bucket?->key(),
            'setup_bucket_label' => $bucket?->label(),
        ];
    }

    /**
     * The live lifecycle numbers for a holding.
     *
     * @return array<string, mixed>
     */
    private function positionBlock(PositionRiskState $state, AssetTechnicalSnapshot $snapshot, StrategyProfile $profile): array
    {
        $entry = (float) $state->entry_price;
        $close = $snapshot->close;

        return [
            'qty_shares' => (float) $state->qty_shares,
            'entry_price' => $entry,
            'opened_at' => $state->opened_at?->toDateString(),
            'current_gain_pct' => $entry > 0 ? round((($close - $entry) / $entry) * 100.0, 4) : null,
            'highest_price_since_entry' => $state->highest_price_since_entry === null ? null : (float) $state->highest_price_since_entry,
            'trailing_active' => (bool) $state->trailing_active,
            'trailing_activated_at' => $state->trailing_activated_at?->toDateString(),
            'trailing_activation_price' => $state->trailing_activation_price === null ? null : (float) $state->trailing_activation_price,
            'distance_to_activation_pct' => ($state->trailing_active || $close <= 0 || $state->trailing_activation_price === null)
                ? null
                : round(((float) $state->trailing_activation_price - $close) / $close * 100.0, 4),
            'profit_floor_price' => $state->profit_floor_price === null ? null : (float) $state->profit_floor_price,
            'trailing_stop_price' => $state->trailing_stop_price === null ? null : (float) $state->trailing_stop_price,
            'initial_stop_price' => $state->initial_stop_price === null ? null : (float) $state->initial_stop_price,
            'effective_stop_price' => $state->effective_stop_price === null ? null : (float) $state->effective_stop_price,
            'locked_profit_pct' => ($entry > 0 && $state->effective_stop_price !== null)
                ? round((((float) $state->effective_stop_price - $entry) / $entry) * 100.0, 4)
                : null,
            'stop_updated_at' => $state->stop_updated_at?->toDateString(),
            'evaluated_through' => $state->evaluated_through?->toDateString(),
            'latest_action' => $state->latest_action,
            'latest_reasons' => $state->latest_reasons ?? [],
            'strategy_version' => $profile->version,
        ];
    }

    /**
     * The status rules, in the order they are applied.
     *
     * @param  array<string, mixed>  $plan
     * @return array{status: string, reasons: array<int, string>}
     */
    private function resolveStatus(
        AssetTechnicalSnapshot $snapshot,
        WatchlistScore $score,
        array $plan,
        ?bool $validLongSetup,
        bool $hardDistribution,
        Carbon $signalDate,
        ?string $latestSession,
        ?string $brokerWindowTo,
        int $brokerLagDays,
        float $minScore,
        float $minRr,
    ): array {
        $reasons = [];

        // 1. Disqualifying conditions first. A stale AVOID is still an AVOID:
        //    knowing the setup is broken does not depend on it being fresh.
        if ($hardDistribution) {
            return [
                'status' => ExecutionStatus::AVOID,
                'reasons' => ['hard distribution: a concentrated seller is working the book'],
            ];
        }

        if ($validLongSetup === false) {
            return [
                'status' => ExecutionStatus::AVOID,
                'reasons' => ['not a valid long setup on the session\'s broker and price behaviour'],
            ];
        }

        if (! $plan['valid']) {
            return [
                'status' => ExecutionStatus::AVOID,
                'reasons' => $plan['notes'] === []
                    ? ['no measurable invalidation level, so no risk can be sized']
                    : $plan['notes'],
            ];
        }

        // 2. Freshness. An execution plan is only as meaningful as the session
        //    behind it, so a signal that is not the latest completed one is
        //    reported as stale rather than presented as actionable.
        if ($latestSession !== null && $snapshot->asOfDate < $latestSession) {
            return [
                'status' => ExecutionStatus::STALE,
                'reasons' => [sprintf(
                    'signal is from %s but the latest completed session is %s',
                    $snapshot->asOfDate,
                    $latestSession,
                )],
            ];
        }

        if ($brokerWindowTo === null) {
            $reasons[] = 'no broker window covers this session';
        } elseif (Carbon::parse($brokerWindowTo)->diffInDays($signalDate) > $brokerLagDays) {
            return [
                'status' => ExecutionStatus::STALE,
                'reasons' => [sprintf(
                    'broker data ends %s, more than %d days before the signal',
                    $brokerWindowTo,
                    $brokerLagDays,
                )],
            ];
        }

        // 3. The READY conjunction. Every failure is named so the row explains
        //    itself without the user reconciling twenty columns by eye.
        $blockers = [];

        if (! $snapshot->uptrend) {
            $blockers[] = 'below the 150-session trend average';
        }

        if (! $score->lf_pass) {
            $blockers[] = 'liquidity filter fails';
        }

        if (! $score->rrf_pass) {
            $blockers[] = 'risk/reward filter fails at the signal close';
        }

        if ((float) $score->score_total < $minScore) {
            $blockers[] = sprintf('score %.1f below the %.1f threshold', (float) $score->score_total, $minScore);
        }

        // The one that catches a setup that looks fine on the screen and is
        // not: strong at the close, thin at the price you would actually pay.
        if ($plan['risk_reward'] === null || $plan['risk_reward'] < $minRr) {
            $blockers[] = $plan['risk_reward'] === null
                ? 'no risk/reward measurable at the planned entry trigger'
                : sprintf(
                    'R/R at the entry trigger is %.2f, below the %.2f minimum (%.2f at the close)',
                    $plan['risk_reward'],
                    $minRr,
                    (float) ($score->risk_reward ?? 0),
                );
        }

        if ($blockers === []) {
            return [
                'status' => ExecutionStatus::READY,
                'reasons' => array_merge($reasons, [sprintf(
                    'score %.1f, R/R %.2f at the trigger, both filters pass, in uptrend',
                    (float) $score->score_total,
                    (float) $plan['risk_reward'],
                )]),
            ];
        }

        return [
            'status' => ExecutionStatus::WATCH,
            'reasons' => array_merge($reasons, $blockers),
        ];
    }

    /**
     * The most recent scan date that actually has scores.
     */
    private function resolveSignalDate(?string $requested, string $version): ?Carbon
    {
        if ($requested !== null && trim($requested) !== '') {
            return Carbon::parse(trim($requested))->startOfDay();
        }

        $latest = WatchlistScore::query()->where('version', $version)->max('scan_date');

        return ($latest === null || $latest === '')
            ? null
            : Carbon::parse((string) $latest)->startOfDay();
    }

    /**
     * @param  array<int, string>|null  $symbols
     * @return array<string, WatchlistScore>
     */
    private function loadScores(Carbon $signalDate, string $version, ?array $symbols): array
    {
        $query = WatchlistScore::query()
            ->whereDate('scan_date', $signalDate->toDateString())
            ->where('version', $version);

        if ($symbols !== null && $symbols !== []) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        }

        $out = [];

        foreach ($query->get() as $row) {
            $out[(string) $row->symbol] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, object>
     */
    private function loadFeatures(array $symbols, Carbon $signalDate): array
    {
        if ($symbols === []) {
            return [];
        }

        $rows = DB::table('features_daily')
            ->whereIn('symbol', $symbols)
            ->whereDate('date', $signalDate->toDateString())
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->symbol] = $row;
        }

        return $out;
    }

    /**
     * Yesterday's rank and score, so today's movement is readable.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array{rank: int, score: float}>
     */
    private function loadPreviousScores(Carbon $signalDate, string $version, array $symbols): array
    {
        $previousDate = WatchlistScore::query()
            ->where('version', $version)
            ->whereDate('scan_date', '<', $signalDate->toDateString())
            ->max('scan_date');

        if ($previousDate === null || $previousDate === '') {
            return [];
        }

        $rows = WatchlistScore::query()
            ->whereDate('scan_date', Carbon::parse((string) $previousDate)->toDateString())
            ->where('version', $version)
            ->orderByDesc('score_total')
            ->orderBy('symbol')
            ->get(['symbol', 'score_total']);

        $out = [];
        $rank = 0;

        foreach ($rows as $row) {
            $rank++;

            if (in_array((string) $row->symbol, $symbols, true)) {
                $out[(string) $row->symbol] = ['rank' => $rank, 'score' => (float) $row->score_total];
            }
        }

        return $out;
    }

    /**
     * Consecutive sessions each symbol has cleared the score threshold, and
     * consecutive sessions it has been scored at all.
     *
     * Deliberately not a trading rule. "Score 82, rank 4, rank 7 yesterday,
     * third session above the threshold" is a far more useful sentence than
     * "rank 4", and it is contextual information, not a signal.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array{ready: int, watch: int}>
     */
    private function loadStatusStreaks(Carbon $signalDate, string $version, array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        $minScore = (float) config('execution.min_score', 75.0);

        $rows = WatchlistScore::query()
            ->whereIn('symbol', $symbols)
            ->where('version', $version)
            ->whereDate('scan_date', '<=', $signalDate->toDateString())
            ->orderByDesc('scan_date')
            ->get(['symbol', 'scan_date', 'score_total', 'lf_pass', 'rrf_pass']);

        $bySymbol = [];

        foreach ($rows as $row) {
            $bySymbol[(string) $row->symbol][] = $row;
        }

        $out = [];

        foreach ($bySymbol as $symbol => $history) {
            $ready = 0;
            $watch = 0;

            foreach ($history as $row) {
                $qualifies = (float) $row->score_total >= $minScore
                    && (bool) $row->lf_pass
                    && (bool) $row->rrf_pass;

                if (! $qualifies) {
                    break;
                }

                $ready++;
            }

            foreach ($history as $row) {
                if ((float) $row->score_total <= 0.0) {
                    break;
                }

                $watch++;
            }

            $out[$symbol] = ['ready' => $ready, 'watch' => $watch];
        }

        return $out;
    }

    /**
     * What the selected portfolio already holds, so a candidate can say so.
     *
     * Reads the portfolio ledger through the same calculator the portfolio
     * pages use rather than re-deriving holdings here.
     *
     * @return array<int, array{qty: float, avg_cost: float}>
     */
    private function loadHoldings(?int $portfolioId): array
    {
        if ($portfolioId === null) {
            return [];
        }

        $portfolio = Portfolio::query()
            ->with(['positions.asset.latestPriceRecord', 'cashMovements'])
            ->find($portfolioId);

        if ($portfolio === null) {
            return [];
        }

        $summary = app(PortfolioCalculator::class)->compute($portfolio);

        $out = [];

        foreach ($summary['holdings'] as $holding) {
            if ((float) $holding['qty'] <= 0) {
                continue;
            }

            $out[(int) $holding['asset_id']] = [
                'qty' => (float) $holding['qty'],
                'avg_cost' => (float) $holding['avg_cost'],
            ];
        }

        return $out;
    }

    private function latestSessionDate(): ?string
    {
        $latest = Price::query()->max('date');

        return ($latest === null || $latest === '')
            ? null
            : Carbon::parse((string) $latest)->toDateString();
    }

    /**
     * The next session the market is actually open, from `trading_days`.
     *
     * Never signal date + 1: a Friday signal is actionable on Monday, and a
     * signal before a holiday on the day after it.
     */
    private function nextTradingDate(Carbon $signalDate): ?Carbon
    {
        $next = TradingDay::query()
            ->whereDate('date', '>', $signalDate->toDateString())
            ->orderBy('date')
            ->value('date');

        return ($next === null || $next === '')
            ? null
            : Carbon::parse((string) $next)->startOfDay();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function freshness(Carbon $signalDate, ?string $latestSession, array $rows): array
    {
        $featureDates = array_filter(array_map(
            static fn (array $row): ?string => $row['data_freshness']['feature_date'] ?? null,
            $rows,
        ));

        $brokerDates = array_filter(array_map(
            static fn (array $row): ?string => $row['data_freshness']['broker_window_to'] ?? null,
            $rows,
        ));

        return [
            'signal_date' => $signalDate->toDateString(),
            'latest_price_date' => $latestSession,
            'latest_feature_date' => $featureDates === [] ? null : max($featureDates),
            'latest_broker_window_date' => $brokerDates === [] ? null : max($brokerDates),
            'signal_is_latest_session' => $latestSession !== null && $signalDate->toDateString() >= $latestSession,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countStatuses(array $rows): array
    {
        $counts = array_fill_keys(ExecutionStatus::ALL, 0);
        $counts['TOTAL'] = count($rows);

        foreach ($rows as $row) {
            $counts[$row['execution_status']] = ($counts[$row['execution_status']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows, array $options, float $minScore, float $minRr): array
    {
        $statuses = $options['statuses'] ?? null;
        $filterScore = $options['min_score'] ?? null;
        $filterRr = $options['min_rr'] ?? null;

        return array_values(array_filter($rows, static function (array $row) use ($statuses, $filterScore, $filterRr): bool {
            if ($statuses !== null && $statuses !== [] && ! in_array($row['execution_status'], $statuses, true)) {
                return false;
            }

            if ($filterScore !== null && $row['execution_score'] < (float) $filterScore) {
                return false;
            }

            if ($filterRr !== null && ($row['planned_risk_reward'] === null || $row['planned_risk_reward'] < (float) $filterRr)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Broker flow for every asset on the page, in one query.
     *
     * Bounded two ways. `end_date <= signalDate` is the leak guard: a rollup
     * that ends after the session being scored describes buying that had not
     * happened yet. The lower bound is only a cost control -- the newest
     * rollup for a live asset is days old, not months, and without a floor
     * this reads years of history to discard almost all of it.
     *
     * @param  array<int, int>  $assetIds
     * @param  array<string, object>  $features
     * @param  Collection<int, Asset>  $assets
     * @param  array<int, BrokerSummaryWindow>  $brokerWindows
     * @return array<int, BrokerFlowAssessment>
     */
    private function loadBrokerFlows(
        array $assetIds,
        Carbon $signalDate,
        StrategyProfile $profile,
        array $features,
        $assets,
        array $brokerWindows,
    ): array {
        if ($assetIds === []) {
            return [];
        }

        $rows = DB::table('broker_accumulation_windows')
            ->whereIn('asset_id', $assetIds)
            ->whereIn('window_days', $profile->brokerWindows)
            ->whereDate('end_date', '<=', $signalDate->toDateString())
            ->whereDate('end_date', '>=', $signalDate->copy()->subDays(45)->toDateString())
            ->orderBy('asset_id')
            ->orderByDesc('end_date')
            ->get();

        // Newest first, so the first row seen for each (asset, window) is the
        // most recent one at or before the signal date.
        $latest = [];
        $rollupEnd = [];

        foreach ($rows as $row) {
            $assetId = (int) $row->asset_id;
            $days = (int) $row->window_days;

            if (isset($latest[$assetId][$days])) {
                continue;
            }

            $latest[$assetId][$days] = (array) $row;

            // Provenance for the freshness gate: the newest rollup actually
            // used is what the regime was computed from, and therefore what
            // "is this broker data current" is a question about.
            $endDate = Carbon::parse((string) $row->end_date)->toDateString();
            $rollupEnd[$assetId] = max($rollupEnd[$assetId] ?? '', $endDate);
        }

        $out = [];

        foreach ($assets as $asset) {
            $assetId = (int) $asset->id;
            $feature = $features[$asset->symbol] ?? null;
            $pbas = ($feature !== null && $feature->pbas !== null) ? (int) $feature->pbas : null;

            $out[$assetId] = $this->brokerFlow->analyze(
                $latest[$assetId] ?? [],
                $profile,
                $pbas,
                // The rollups the flow was measured from, falling back to the
                // imported summary window when no rollup covers the asset.
                $rollupEnd[$assetId] ?? ($brokerWindows[$assetId] ?? null)?->to_date?->toDateString(),
            );
        }

        return $out;
    }

    /**
     * One session's bars for many assets, keyed by asset id.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, object>
     */
    private function loadBars(array $assetIds, Carbon $date): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', $date->toDateString())
            ->get(['asset_id', 'date', 'open', 'high', 'low', 'close']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->asset_id] = $row;
        }

        return $out;
    }

    /**
     * Bring the selected portfolio's lifecycle states up to date.
     *
     * @param  array<int, BrokerFlowAssessment>  $flows
     * @return array<int, PositionRiskState>
     */
    private function refreshLifecycle(?int $portfolioId, StrategyProfile $profile, Carbon $signalDate, array $flows): array
    {
        if ($portfolioId === null) {
            return [];
        }

        $portfolio = Portfolio::query()->find($portfolioId);

        if ($portfolio === null) {
            return [];
        }

        return $this->lifecycle->refresh($portfolio, $profile, $signalDate, $flows);
    }

    /**
     * Whether the inputs behind this row are current enough to act on.
     *
     * Execution wants broker data describing the latest completed session --
     * a tighter requirement than the analytical view's, and deliberately so.
     * The looser lag is fine for asking what has been accumulating; it is not
     * fine for placing an order tomorrow morning on the strength of it.
     *
     * @return array<string, mixed>
     */
    private function dataQuality(
        AssetTechnicalSnapshot $snapshot,
        Carbon $signalDate,
        ?string $latestSession,
        ?string $nextTradingDate,
        ?string $brokerWindowTo,
        StrategyProfile $profile,
    ): array {
        // The plan is for T+1, so a bar existing for T+1 is the situation the
        // plan was written for, not evidence that it has gone stale. That is
        // the one session of lead the whole T -> T+1 design is about: the
        // signal is built from T's completed bar and acted on during T+1.
        // Two or more sessions behind is a different matter -- there the
        // reader should be looking at a newer signal, and the row says so.
        $ohlcvCurrent = $latestSession === null
            || $snapshot->asOfDate >= $latestSession
            || ($nextTradingDate !== null && $latestSession <= $nextTradingDate);

        $brokerLagDays = null;
        $brokerCurrent = false;

        if ($brokerWindowTo !== null) {
            $brokerLagDays = (int) Carbon::parse($brokerWindowTo)->diffInDays($signalDate);
            $brokerCurrent = $brokerLagDays <= $profile->maxBrokerLagDaysExecution;
        }

        $reasons = [];

        if (! $ohlcvCurrent) {
            $reasons[] = sprintf('price data ends %s but the latest session is %s', $snapshot->asOfDate, (string) $latestSession);
        }

        if ($brokerWindowTo === null) {
            $reasons[] = 'no broker window covers this session';
        } elseif (! $brokerCurrent) {
            $reasons[] = sprintf('broker data ends %s, %d session(s) behind the signal', $brokerWindowTo, (int) $brokerLagDays);
        }

        return [
            'broker_current' => $brokerCurrent,
            'ohlcv_current' => $ohlcvCurrent,
            'broker_window_to' => $brokerWindowTo,
            'broker_lag_days' => $brokerLagDays,
            'max_broker_lag_days' => $profile->maxBrokerLagDaysExecution,
            'price_date' => $snapshot->asOfDate,
            'latest_session' => $latestSession,
            'reasons' => $reasons,
        ];
    }

    /**
     * Where this symbol sits in the lifecycle, and what to do about it.
     *
     * Ordered so the strongest claim wins. A holding is reported as a holding
     * before anything else, because showing the same symbol as an unrelated
     * watchlist candidate and as an open position is how a second lot gets
     * bought by accident.
     *
     * @param  array<string, mixed>  $plan
     * @param  array{state: string, extension_atr: ?float, extension_pct: ?float, reason: string}  $zone
     * @param  array<string, mixed>  $dataQuality
     * @param  array<string, mixed>|null  $holding
     * @return array{status: string, action: string, reasons: array<int, string>}
     */
    private function resolveLifecycleStatus(
        AssetTechnicalSnapshot $snapshot,
        BrokerFlowAssessment $flow,
        array $plan,
        array $zone,
        array $dataQuality,
        ?PositionRiskState $riskState,
        ?array $holding,
        StrategyProfile $profile,
    ): array {
        // 1. Already held. One symbol, one state.
        if ($riskState !== null && ! $riskState->closed) {
            $action = (string) ($riskState->latest_action ?? PositionAction::HOLD);

            $status = match ($action) {
                PositionAction::EXIT_TRIGGERED => ExecutionStatus::EXIT,
                PositionAction::TRAILING_ACTIVE => ExecutionStatus::TRAILING,
                default => $riskState->trailing_active ? ExecutionStatus::TRAILING : ExecutionStatus::HOLD,
            };

            return [
                'status' => $status,
                'action' => $action,
                'reasons' => (array) ($riskState->latest_reasons ?? []),
            ];
        }

        // A holding the lifecycle has not evaluated yet still must not be
        // offered as a fresh entry.
        if ($holding !== null && (float) ($holding['qty'] ?? 0) > 0) {
            return [
                'status' => ExecutionStatus::HOLD,
                'action' => PositionAction::HOLD,
                'reasons' => ['the portfolio already holds this position'],
            ];
        }

        // 2. Disqualifying facts about the setup, which staleness does not
        //    change: knowing a setup is broken does not require it to be fresh.
        if (BrokerRegime::isDistributive($flow->regime)) {
            return [
                'status' => ExecutionStatus::AVOID,
                'action' => PositionAction::AVOID,
                'reasons' => array_merge([sprintf('broker regime is %s', $flow->regime)], $flow->reasons),
            ];
        }

        if (($plan['valid'] ?? false) === false) {
            return [
                'status' => ExecutionStatus::AVOID,
                'action' => PositionAction::AVOID,
                'reasons' => $plan['notes'] ?? ['no valid execution plan'],
            ];
        }

        // 3. Freshness gates everything actionable.
        if (! $dataQuality['ohlcv_current'] || ! $dataQuality['broker_current']) {
            return [
                'status' => ExecutionStatus::STALE_DATA,
                'action' => PositionAction::STALE_DATA,
                'reasons' => $dataQuality['reasons'],
            ];
        }

        // 4. The setup itself.
        if ($snapshot->isBreakout20()) {
            if ($zone['state'] === 'above') {
                return [
                    'status' => ExecutionStatus::NO_CHASE,
                    'action' => PositionAction::NO_CHASE,
                    'reasons' => [$zone['reason']],
                ];
            }

            return [
                'status' => ExecutionStatus::TRIGGERED,
                'action' => PositionAction::BUY_ON_TRIGGER,
                'reasons' => [
                    sprintf('20-session breakout confirmed at %.4f', $snapshot->close),
                    sprintf('entry zone %.4f to %.4f', (float) $plan['entry_zone_low'], (float) $plan['entry_zone_high']),
                    sprintf('initial risk %.2f%%', (float) $plan['initial_risk_pct']),
                ],
            ];
        }

        $distance = $snapshot->distanceToBreakoutAtr();

        if ($distance !== null && $distance <= $profile->armedDistanceAtr && BrokerRegime::isAccumulative($flow->regime)) {
            return [
                'status' => ExecutionStatus::ARMED,
                'action' => PositionAction::WAIT_FOR_BREAKOUT,
                'reasons' => [
                    sprintf('%.2f ATR below the 20-session high', $distance),
                    sprintf('broker regime is %s', $flow->regime),
                ],
            ];
        }

        return [
            'status' => ExecutionStatus::WATCH,
            'action' => PositionAction::WATCH,
            'reasons' => array_merge(
                $distance === null ? ['no breakout reference available'] : [sprintf('%.2f ATR below the 20-session high', $distance)],
                [sprintf('broker regime is %s', $flow->regime)],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function empty(string $version, array $options, ?Carbon $signalDate = null): array
    {
        return [
            'signal_date' => $signalDate?->toDateString(),
            'next_trading_date' => $signalDate === null ? null : $this->nextTradingDate($signalDate)?->toDateString(),
            'version' => $version,
            'thresholds' => [
                'min_score' => (float) ($options['min_score'] ?? config('execution.min_score', 75.0)),
                'min_rr' => (float) ($options['min_rr'] ?? config('execution.min_rr', 2.0)),
                'max_entry_gap_pct' => config('execution.max_entry_gap_pct'),
            ],
            'freshness' => [
                'signal_date' => $signalDate?->toDateString(),
                'latest_price_date' => $this->latestSessionDate(),
                'latest_feature_date' => null,
                'latest_broker_window_date' => null,
                'signal_is_latest_session' => false,
            ],
            'counts' => array_merge(array_fill_keys(ExecutionStatus::ALL, 0), ['TOTAL' => 0]),
            'total' => 0,
            'rows' => [],
            'strategy_profile' => StrategyProfile::fromConfig()->toArray(),
            'costs' => (new TradingCostModel(StrategyProfile::fromConfig()))->toArray(),
            'disclaimer' => (string) config('execution.disclaimer'),
            'outcome_disclaimer' => StrategyProfile::fromConfig()->disclaimer,
        ];
    }
}
