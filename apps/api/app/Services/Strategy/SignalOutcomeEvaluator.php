<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\StrategySignalOutcome;
use App\Models\TradingDay;
use App\Models\WatchlistScore;
use App\Services\Analysis\AssetTechnicalSnapshot;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use App\Services\Execution\ExecutionPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the historical record the probability engine reads.
 *
 * For every scored session it reconstructs the setup exactly as it stood --
 * an as-of snapshot, broker rollups that ended on or before that date, the
 * plan those two produce -- and then, and only then, looks forward.
 *
 * The separation is the whole design. Signal construction is handed nothing
 * dated after the signal session; the simulator is handed nothing dated
 * before the entry. There is no object in this class that holds both, so
 * there is no path along which a forward bar could reach a scoring decision.
 * That is what the leak test asserts: change the bars after a signal and the
 * signal's own fields do not move.
 *
 * Entry follows the live rule rather than a convenient one. The plan says the
 * next session must trade through the trigger; if it does not, there is no
 * trade and no outcome row. Recording an entry at the next open regardless
 * would fill a database with trades the strategy would never have taken, and
 * every statistic drawn from it would describe a different strategy.
 */
class SignalOutcomeEvaluator
{
    public function __construct(
        private readonly AssetTechnicalSnapshotService $snapshots,
        private readonly ExecutionPlanner $planner,
        private readonly BrokerFlowAnalyzer $brokerFlow,
        private readonly SignalOutcomeSimulator $simulator,
        private readonly ExecutionScoreV2 $scoreV2,
    ) {}

    /**
     * @param  array<int, string>|null  $symbols
     * @return array{
     *   signals: int,
     *   evaluated: int,
     *   persisted: int,
     *   not_triggered: int,
     *   no_plan: int,
     *   missing_data: int,
     *   resolved: int,
     *   from: string,
     *   to: string,
     *   strategy_version: string,
     * }
     */
    public function evaluate(
        Carbon $from,
        Carbon $to,
        StrategyProfile $profile,
        ?array $symbols = null,
        string $scoreVersion = StrategyScoringService::VERSION,
    ): array {
        $costs = new TradingCostModel($profile);
        $tradingDays = $this->tradingDays();
        $index = array_flip($tradingDays);

        $report = [
            'signals' => 0,
            'evaluated' => 0,
            'persisted' => 0,
            'not_triggered' => 0,
            'no_plan' => 0,
            'missing_data' => 0,
            'resolved' => 0,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'strategy_version' => $profile->version,
        ];

        foreach ($this->fills($from, $to, $profile, $symbols, $scoreVersion, $report) as $fill) {
            $outcome = $this->simulator->simulate(
                $fill['fill_price'],
                $fill['initial_stop'],
                $fill['entry_date'],
                $fill['forward_bars'],
                $profile,
                $costs,
            );

            $this->persist($fill, $outcome, $profile);

            $report['persisted']++;

            if ($outcome->resolved && $outcome->fiveVersusStopResolved()) {
                $report['resolved']++;
            }
        }

        return $report;
    }

    /**
     * Every signal in the range that produced a real fill, with the forward
     * bars needed to simulate it.
     *
     * Public because the parameter grid needs exactly this and must not
     * rebuild it per cell: the trailing parameters change how a fill is
     * *managed*, never whether it happened, so regenerating signals for each
     * grid cell would be both slow and subtly wrong -- any difference in the
     * signal set between two cells makes their statistics incomparable.
     *
     * @param  array<int, string>|null  $symbols
     * @param  array<string, int>  $report  counters, mutated in place
     * @return \Generator<int, array<string, mixed>>
     */
    public function fills(
        Carbon $from,
        Carbon $to,
        StrategyProfile $profile,
        ?array $symbols,
        string $scoreVersion,
        array &$report,
    ): \Generator {
        $tradingDays = $this->tradingDays();
        $index = array_flip($tradingDays);
        $scansBySymbol = $this->loadSignals($from, $to, $scoreVersion, $symbols);

        if ($scansBySymbol === []) {
            return;
        }

        $assets = Asset::query()
            ->whereIn('symbol', array_keys($scansBySymbol))
            ->get(['id', 'symbol', 'name', 'sector']);

        // The deepest the simulator can look forward: the holding cap plus
        // the longest excursion horizon, so a bar window is loaded once per
        // asset rather than once per signal.
        $forwardDepth = $profile->maxHoldingSessions + max($profile->outcomeHorizons ?: [20]);

        foreach ($assets as $asset) {
            $scans = $scansBySymbol[$asset->symbol] ?? [];

            if ($scans === []) {
                continue;
            }

            $bars = $this->loadBars(
                (int) $asset->id,
                Carbon::parse(min(array_keys($scans)))->subDays(5),
                $to->copy()->addDays((int) ($forwardDepth * 1.8) + 10),
            );

            foreach ($scans as $scanDate => $score) {
                $report['signals']++;

                $fill = $this->buildFill(
                    $asset,
                    (string) $scanDate,
                    $bars,
                    $tradingDays,
                    $index,
                    $forwardDepth,
                    $profile,
                );

                if (is_string($fill)) {
                    $report[$fill]++;

                    continue;
                }

                $report['evaluated']++;

                yield $fill;
            }
        }
    }

    /**
     * One signal, reconstructed as of its own session, and the fill it
     * produced -- or the reason there was none.
     *
     * @param  array<string, array<string, mixed>>  $bars
     * @param  array<int, string>  $tradingDays
     * @param  array<string, int>  $index
     * @return array<string, mixed>|string
     */
    private function buildFill(
        Asset $asset,
        string $scanDate,
        array $bars,
        array $tradingDays,
        array $index,
        int $forwardDepth,
        StrategyProfile $profile,
    ): array|string {
        if (! isset($index[$scanDate]) || ! isset($bars[$scanDate])) {
            return 'missing_data';
        }

        $signalDate = Carbon::parse($scanDate);

        // --- Everything from here to the plan sees only data at or before
        // --- the signal session.
        $snapshot = $this->snapshots->snapshotForAssetAsOf($asset, $signalDate);

        if ($snapshot === null) {
            return 'missing_data';
        }

        $flow = $this->brokerFlow->analyze(
            $this->brokerRollups((int) $asset->id, $signalDate, $profile),
            $profile,
        );

        $plan = $this->planner->planForProfile($snapshot, $profile);

        if (($plan['valid'] ?? false) === false || ($plan['trigger_price'] ?? null) === null) {
            return 'no_plan';
        }

        $bucket = SetupBucket::fromSnapshot($snapshot, $flow->regime, $plan['initial_risk_pct'] ?? null, $profile);

        $scored = $this->scoreV2->score(
            $flow,
            $snapshot,
            $plan,
            ['status' => OutcomeProbabilityService::INSUFFICIENT_SAMPLE, 'sample_size' => 0, 'minimum_sample' => $profile->minimumProbabilitySample],
            null,
            null,
            $profile,
        );

        // --- The forward half begins here. Nothing above may be revisited.
        $entryDate = $tradingDays[$index[$scanDate] + 1] ?? null;

        if ($entryDate === null || ! isset($bars[$entryDate])) {
            return 'missing_data';
        }

        $entryBar = $bars[$entryDate];
        $trigger = (float) $plan['trigger_price'];

        if ((float) $entryBar['high'] < $trigger) {
            return 'not_triggered';
        }

        // You cannot buy below the session's first print, so a session that
        // opens above the trigger fills at the open.
        $fillPrice = max($trigger, (float) ($entryBar['open'] ?? $trigger));

        return [
            'asset' => $asset,
            'signal_date' => $signalDate,
            'entry_date' => $entryDate,
            'snapshot' => $snapshot,
            'flow' => $flow,
            'plan' => $plan,
            'bucket' => $bucket,
            'execution_score' => $scored['score'],
            'fill_price' => $fillPrice,
            'initial_stop' => $plan['initial_stop'] ?? null,
            'forward_bars' => $this->forwardBars($bars, $tradingDays, $index, $entryDate, $forwardDepth),
        ];
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private function persist(array $fill, SignalOutcome $outcome, StrategyProfile $profile): void
    {
        /** @var Asset $asset */
        $asset = $fill['asset'];
        /** @var AssetTechnicalSnapshot $snapshot */
        $snapshot = $fill['snapshot'];
        /** @var BrokerFlowAssessment $flow */
        $flow = $fill['flow'];
        /** @var SetupBucket $bucket */
        $bucket = $fill['bucket'];
        $plan = $fill['plan'];
        $entryDate = (string) $fill['entry_date'];
        $signalDate = $fill['signal_date'];
        $executionScore = (float) $fill['execution_score'];

        $attributes = [
            'symbol' => $asset->symbol,
            'entry_date' => $entryDate,
            'setup_bucket' => $bucket->key(),
            'broker_regime' => $flow->regime,
            'broker_persistence_ratio' => $flow->persistenceRatio,
            'positive_broker_windows' => $flow->positiveWindows,
            'available_broker_windows' => $flow->availableWindows,
            'broker_acceleration' => $flow->acceleration,
            'execution_score' => $executionScore,
            'breakout20' => $snapshot->isBreakout20(),
            'breakout55' => $snapshot->isBreakout55(),
            'vol_ratio_20' => $snapshot->volRatio20,
            'close_pos' => $snapshot->closePos,
            'atr14' => $snapshot->atr14,
            'trigger_price' => $plan['trigger_price'],
            'entry_price' => $outcome->entryPrice,
            'initial_stop' => $outcome->initialStopPrice,
            'initial_risk_pct' => $outcome->initialRiskPct,
            'hit_5pct' => $outcome->hitFivePct,
            'days_to_5pct' => $outcome->daysToFivePct,
            'hit_initial_stop' => $outcome->hitInitialStop,
            'days_to_initial_stop' => $outcome->daysToInitialStop,
            'hit_stop_before_5pct' => $outcome->hitStopBeforeFivePct,
            'reached_5pct_before_stop' => $outcome->reachedFivePctBeforeStop,
            'trailing_activated' => $outcome->trailingActivated,
            'trailing_activated_at' => $outcome->trailingActivatedAt,
            'exit_date' => $outcome->exitDate,
            'exit_price' => $outcome->exitPrice,
            'exit_reason' => $outcome->exitReason,
            'max_gain_before_exit_pct' => $outcome->maxGainBeforeExitPct,
            'gross_return_pct' => $outcome->grossReturnPct,
            'net_return_pct' => $outcome->netReturnPct,
            'hold_sessions' => $outcome->holdSessions,
            // Only a trade that finished counts toward a statistic. One whose
            // forward window ran out has no answer, and both the probability
            // pass and the lifecycle pass have to have produced one.
            'resolved' => $outcome->resolved && $outcome->fiveVersusStopResolved(),
            'context' => [
                'setup_bucket_label' => $bucket->label(),
                'entry_reason' => $plan['initial_stop_source'] ?? null,
                'broker_reasons' => $flow->reasons,
                'plan_notes' => $plan['notes'] ?? [],
            ],
        ];

        foreach ([1, 3, 5, 10, 20] as $horizon) {
            $attributes['mfe_'.$horizon.'d'] = $outcome->mfe[$horizon] ?? null;
            $attributes['mae_'.$horizon.'d'] = $outcome->mae[$horizon] ?? null;
        }

        StrategySignalOutcome::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'signal_date' => $signalDate->toDateString(),
                'strategy_version' => $profile->version,
            ],
            $attributes,
        );
    }

    /**
     * Sessions strictly after the entry, bounded by the simulator's reach.
     *
     * @param  array<string, array<string, mixed>>  $bars
     * @param  array<int, string>  $tradingDays
     * @param  array<string, int>  $index
     * @return array<int, array<string, mixed>>
     */
    private function forwardBars(array $bars, array $tradingDays, array $index, string $entryDate, int $depth): array
    {
        $start = $index[$entryDate] ?? null;

        if ($start === null) {
            return [];
        }

        $out = [];

        for ($offset = 1; $offset <= $depth; $offset++) {
            $date = $tradingDays[$start + $offset] ?? null;

            if ($date === null) {
                break;
            }

            if (isset($bars[$date])) {
                $out[] = $bars[$date];
            }
        }

        return $out;
    }

    /**
     * The newest rollup per window length at or before the signal date.
     *
     * @return array<int, array<string, mixed>>
     */
    private function brokerRollups(int $assetId, Carbon $signalDate, StrategyProfile $profile): array
    {
        $rows = DB::table('broker_accumulation_windows')
            ->where('asset_id', $assetId)
            ->whereIn('window_days', $profile->brokerWindows)
            ->whereDate('end_date', '<=', $signalDate->toDateString())
            ->whereDate('end_date', '>=', $signalDate->copy()->subDays(45)->toDateString())
            ->orderByDesc('end_date')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $days = (int) $row->window_days;

            if (! isset($out[$days])) {
                $out[$days] = (array) $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>|null  $symbols
     * @return array<string, array<string, WatchlistScore>>
     */
    private function loadSignals(Carbon $from, Carbon $to, string $version, ?array $symbols): array
    {
        $query = WatchlistScore::query()
            ->where('version', $version)
            ->whereDate('scan_date', '>=', $from->toDateString())
            ->whereDate('scan_date', '<=', $to->toDateString())
            ->orderBy('scan_date');

        if ($symbols !== null && $symbols !== []) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        }

        $out = [];

        foreach ($query->get() as $row) {
            $out[(string) $row->symbol][Carbon::parse((string) $row->scan_date)->toDateString()] = $row;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function tradingDays(): array
    {
        return TradingDay::query()
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn ($value): string => Carbon::parse((string) $value)->toDateString())
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadBars(int $assetId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('price_bars')
            ->where('asset_id', $assetId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get(['date', 'open', 'high', 'low', 'close']);

        $out = [];

        foreach ($rows as $row) {
            $date = Carbon::parse((string) $row->date)->toDateString();

            $out[$date] = [
                'date' => $date,
                'open' => $row->open === null ? null : (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
            ];
        }

        return $out;
    }
}
