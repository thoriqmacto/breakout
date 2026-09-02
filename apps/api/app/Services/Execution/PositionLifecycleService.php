<?php

namespace App\Services\Execution;

use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PositionRiskState;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use App\Services\Portfolio\PortfolioCalculator;
use App\Services\Strategy\BrokerFlowAnalyzer;
use App\Services\Strategy\BrokerFlowAssessment;
use App\Services\Strategy\PositionAction;
use App\Services\Strategy\StrategyProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The profit lifecycle applied to positions the portfolio actually holds.
 *
 * The entry price here is the portfolio's real average cost, taken from the
 * ledger through PortfolioCalculator -- never a price reconstructed from
 * OHLCV. A holding bought in three fills at three prices has one average cost
 * and the trailing arithmetic has to run off that number, because that is the
 * number the profit is measured against. Deriving an entry from the chart
 * would produce a stop that protects a trade nobody made.
 *
 * State is persisted rather than recomputed on each request for one reason
 * that is not performance: the effective stop may never move down, and a
 * value derived fresh every time has no memory of where it has already been.
 * A profile edit, a corrected bar, or a late fill could all lower a
 * recomputed stop, which is precisely the guarantee the lifecycle rests on.
 *
 * A holding that has been sold is marked closed and stops receiving updates.
 * Without that, a position exited months ago goes on reporting HOLD for ever.
 */
class PositionLifecycleService
{
    public function __construct(
        private readonly PortfolioCalculator $calculator,
        private readonly AssetTechnicalSnapshotService $snapshots,
        private readonly TrailingStopEngine $engine,
        private readonly ExecutionPlanner $planner,
        private readonly BrokerFlowAnalyzer $brokerFlow,
    ) {}

    /**
     * Bring every holding's lifecycle state up to date.
     *
     * @param  array<int, BrokerFlowAssessment>  $brokerFlowByAsset  optional, keyed by asset id
     * @return array<int, PositionRiskState> keyed by asset id
     */
    public function refresh(
        Portfolio $portfolio,
        StrategyProfile $profile,
        ?Carbon $asOf = null,
        array $brokerFlowByAsset = [],
    ): array {
        $asOfDate = ($asOf ? $asOf->copy() : Carbon::now())->startOfDay();

        $portfolio->loadMissing(['positions.asset.latestPriceRecord', 'cashMovements']);
        $summary = $this->calculator->compute($portfolio);

        $open = [];

        foreach ($summary['holdings'] as $holding) {
            if ((float) $holding['qty'] > 0) {
                $open[(int) $holding['asset_id']] = $holding;
            }
        }

        $this->closeDepartedHoldings($portfolio, $profile, array_keys($open), $asOfDate);

        if ($open === []) {
            return [];
        }

        $assets = Asset::query()->whereIn('id', array_keys($open))->get(['id', 'symbol', 'name', 'sector']);
        $states = [];

        foreach ($assets as $asset) {
            $holding = $open[(int) $asset->id] ?? null;

            if ($holding === null) {
                continue;
            }

            $state = $this->refreshHolding(
                $portfolio,
                $asset,
                $holding,
                $profile,
                $asOfDate,
                $brokerFlowByAsset[(int) $asset->id] ?? null,
            );

            if ($state !== null) {
                $states[(int) $asset->id] = $state;
            }
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $holding
     */
    private function refreshHolding(
        Portfolio $portfolio,
        Asset $asset,
        array $holding,
        StrategyProfile $profile,
        Carbon $asOf,
        ?BrokerFlowAssessment $flow,
    ): ?PositionRiskState {
        $entryPrice = (float) $holding['avg_cost'];
        $openedAt = $holding['opened_at'] ?? $holding['last_executed_at'] ?? null;

        if ($entryPrice <= 0 || $openedAt === null) {
            return null;
        }

        $existing = PositionRiskState::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('asset_id', $asset->id)
            ->where('strategy_version', $profile->version)
            ->first();

        // A holding whose entry price or open date has changed -- an
        // additional fill, a corrected import -- is rebuilt from scratch
        // rather than continued, because the old lifecycle was measured
        // against a cost basis that no longer exists.
        $rebuild = $existing === null
            || $existing->closed
            || abs((float) $existing->entry_price - $entryPrice) > 0.0001
            || $existing->opened_at?->toDateString() !== Carbon::parse((string) $openedAt)->toDateString();

        $initialStop = $rebuild
            ? $this->deriveInitialStop($asset, Carbon::parse((string) $openedAt), $entryPrice, $profile)
            : (float) $existing->initial_stop_price;

        $bars = $this->barsSince((int) $asset->id, Carbon::parse((string) $openedAt), $asOf);

        $state = $this->engine->open($entryPrice, $initialStop, Carbon::parse((string) $openedAt)->toDateString(), $profile);

        // Replay session by session rather than applying the maximum high in
        // one step: the activation date is a fact about when a threshold was
        // crossed, and a single max would lose it.
        foreach ($bars as $bar) {
            $state = $this->engine->applyHigh($state, (float) $bar['high'], (string) $bar['date'], $profile);
        }

        $lastClose = $bars === [] ? null : (float) $bars[array_key_last($bars)]['close'];
        $priceWeakening = $this->priceWeakening($bars);

        $deterioration = $flow === null
            ? ['action' => PositionAction::HOLD, 'severity' => 0, 'reasons' => ['no broker assessment supplied']]
            : $this->brokerFlow->deterioration($flow, $priceWeakening);

        $action = $this->action($state, $lastClose, $deterioration);

        $attributes = array_merge($state->toArray(), [
            'qty_shares' => (float) $holding['qty'],
            'entry_price' => $entryPrice,
            'opened_at' => Carbon::parse((string) $openedAt)->toDateString(),
            'evaluated_through' => $bars === [] ? null : (string) $bars[array_key_last($bars)]['date'],
            'latest_broker_regime' => $flow?->regime,
            'latest_action' => $action,
            'latest_reasons' => $deterioration['reasons'],
            'closed' => false,
            'closed_at' => null,
        ]);

        // The DTO's array carries presentation-only keys the table has no
        // columns for.
        unset($attributes['max_gain_pct'], $attributes['locked_profit_pct'], $attributes['sessions_held']);

        return PositionRiskState::updateOrCreate(
            [
                'portfolio_id' => $portfolio->id,
                'asset_id' => $asset->id,
                'strategy_version' => $profile->version,
            ],
            $attributes,
        );
    }

    /**
     * The stop the position would have been opened with.
     *
     * Built from a snapshot as of the entry date, so it uses only what was
     * known then. A holding imported from broker history has no plan attached
     * to it, and inventing one from today's chart would put the stop wherever
     * the last few months happened to leave the swing low.
     */
    private function deriveInitialStop(Asset $asset, Carbon $openedAt, float $entryPrice, StrategyProfile $profile): ?float
    {
        $snapshot = $this->snapshots->snapshotForAssetAsOf($asset, $openedAt);

        if ($snapshot === null) {
            return null;
        }

        $plan = $this->planner->planForProfile($snapshot, $profile);
        $stop = $plan['initial_stop'] ?? null;

        // The plan's stop is measured from the breakout level, which for an
        // entry taken elsewhere can sit above the price actually paid. A stop
        // above the entry is not a stop.
        if ($stop !== null && $stop < $entryPrice) {
            return (float) $stop;
        }

        if ($snapshot->atr14 !== null && $snapshot->atr14 > 0) {
            return round($entryPrice - $profile->initialStopAtrMultiple * $snapshot->atr14, 4);
        }

        return null;
    }

    /**
     * Which state the holding is in, and what to do about it.
     *
     * Broker deterioration can tighten the recommendation but never moves the
     * price stop on its own: the stop is where the idea is wrong, and broker
     * flow is evidence about the idea, not about the level.
     *
     * @param  array{action: string, severity: int, reasons: array<int, string>}  $deterioration
     */
    private function action(TrailingState $state, ?float $lastClose, array $deterioration): string
    {
        if ($lastClose !== null && $state->effectiveStopPrice !== null && $lastClose <= $state->effectiveStopPrice) {
            return PositionAction::EXIT_TRIGGERED;
        }

        if ($deterioration['action'] === PositionAction::EXIT_WARNING) {
            return PositionAction::EXIT_WARNING;
        }

        if ($state->trailingActive) {
            return $deterioration['action'] === PositionAction::HOLD_TIGHTEN_STOP
                ? PositionAction::HOLD_TIGHTEN_STOP
                : PositionAction::TRAILING_ACTIVE;
        }

        return $deterioration['action'];
    }

    /**
     * Whether the recent sessions are making lower closes.
     *
     * The price half of the strong-exit-warning condition, kept deliberately
     * crude: three closes is enough to say a position has stopped working and
     * not enough to fit anything.
     *
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function priceWeakening(array $bars): bool
    {
        if (count($bars) < 3) {
            return false;
        }

        $tail = array_slice($bars, -3);

        return (float) $tail[2]['close'] < (float) $tail[1]['close']
            && (float) $tail[1]['close'] < (float) $tail[0]['close'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function barsSince(int $assetId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('price_bars')
            ->where('asset_id', $assetId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get(['date', 'open', 'high', 'low', 'close']);

        $bars = [];

        foreach ($rows as $row) {
            $bars[] = [
                'date' => Carbon::parse((string) $row->date)->toDateString(),
                'open' => $row->open === null ? null : (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
            ];
        }

        return $bars;
    }

    /**
     * Mark rows for holdings the portfolio no longer has.
     *
     * @param  array<int, int>  $openAssetIds
     */
    private function closeDepartedHoldings(Portfolio $portfolio, StrategyProfile $profile, array $openAssetIds, Carbon $asOf): void
    {
        $query = PositionRiskState::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('strategy_version', $profile->version)
            ->where('closed', false);

        if ($openAssetIds !== []) {
            $query->whereNotIn('asset_id', $openAssetIds);
        }

        $query->update([
            'closed' => true,
            'closed_at' => $asOf->toDateString(),
            'latest_action' => PositionAction::EXIT_TRIGGERED,
            'updated_at' => Carbon::now(),
        ]);
    }
}
