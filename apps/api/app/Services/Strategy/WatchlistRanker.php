<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\BrokerAccumulationWindow;
use App\Models\Metric;
use App\Models\Price;
use App\Models\WatchlistScore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the per-symbol scoring pipeline:
 *   - Pull broker accumulation windows + features_daily + metrics + latest bar
 *   - Apply BAS / BCS / liquidity / risk-reward scoring
 *   - Persist to watchlist_scores (idempotent on (scan_date, symbol, version))
 *   - Return the ranked list with explainable rationale
 *
 * Pure-PHP scoring lives in StrategyScoringService and RiskCalculator.
 * This class is responsible for IO + composition only.
 */
class WatchlistRanker
{
    public const DEFAULT_WINDOWS = [3, 5, 10, 20];

    public const DEFAULT_LIQUIDITY_TURNOVER = 5_000_000_000.0; // IDR 5B

    public const DEFAULT_LIQUIDITY_BROKERS = 5;

    public const DEFAULT_MIN_RR = 2.0;

    public const DEFAULT_TOP = 30;

    public function __construct(
        private readonly StrategyScoringService $scoring,
        private readonly RiskCalculator $risk,
        private readonly BrokerWindowResolver $windows,
    ) {}

    /**
     * @param array{
     *   windows?: array<int, int>,
     *   symbols?: array<int, string>|null,
     *   top?: int,
     *   version?: string,
     *   min_turnover?: float,
     *   min_brokers?: int,
     *   min_rr?: float,
     * } $options
     * @return array{
     *   scan_date: string,
     *   version: string,
     *   evaluated: int,
     *   persisted: int,
     *   rows: array<int, array<string, mixed>>,
     * }
     */
    public function rank(Carbon $scanDate, array $options = []): array
    {
        $windows = $options['windows'] ?? self::DEFAULT_WINDOWS;
        $symbols = $options['symbols'] ?? null;
        $top = max(1, (int) ($options['top'] ?? self::DEFAULT_TOP));
        $version = (string) ($options['version'] ?? StrategyScoringService::VERSION);
        $minTurnover = (float) ($options['min_turnover'] ?? self::DEFAULT_LIQUIDITY_TURNOVER);
        $minBrokers = (int) ($options['min_brokers'] ?? self::DEFAULT_LIQUIDITY_BROKERS);
        $minRR = (float) ($options['min_rr'] ?? self::DEFAULT_MIN_RR);

        $assetMap = $this->loadAssets($symbols);
        $assetIds = array_keys($assetMap);

        if ($assetIds === []) {
            return [
                'scan_date' => $scanDate->toDateString(),
                'version' => $version,
                'evaluated' => 0,
                'persisted' => 0,
                'rows' => [],
            ];
        }

        $windowsByAsset = $this->loadAccumulationWindows($assetIds, $scanDate, $windows);
        $featuresBySymbol = $this->loadFeatures($assetMap, $scanDate);
        $metricsByAsset = Metric::query()->whereIn('asset_id', $assetIds)->get()->keyBy('asset_id');
        $latestBarByAsset = $this->loadLatestBar($assetIds, $scanDate);
        $swingLowByAsset = $this->loadSwingLows($assetIds, $scanDate);

        $rows = [];
        foreach ($assetMap as $assetId => $asset) {
            $features = $featuresBySymbol[$asset->symbol] ?? null;
            $metric = $metricsByAsset->get($assetId);
            $bar = $latestBarByAsset[$assetId] ?? null;
            $accWindows = $windowsByAsset[$assetId] ?? [];

            if ($bar === null || $features === null) {
                continue;
            }

            $bas = $this->scoring->brokerAccumulation(
                $accWindows,
                $features->pbas !== null ? (int) $features->pbas : null
            );
            $bcs = $this->scoring->breakoutConfirmation([
                'breakout20' => (bool) $features->breakout20,
                'close_pos' => (float) ($features->close_pos ?? 0),
                'vol_ratio_20' => (float) ($features->vol_ratio_20 ?? 0),
            ]);

            $turnoverValue = (float) ($features->turnover_value ?? 0);
            $brokerCount = (int) ($features->active_broker_count ?? 0);

            $liquidity = $this->scoring->liquidityFilter($turnoverValue, $brokerCount, $minTurnover, $minBrokers);

            $atr14 = $metric?->atr14 !== null ? (float) $metric->atr14 : null;
            $high55 = $metric?->high55 !== null ? (float) $metric->high55 : null;
            $swingLow = $swingLowByAsset[$assetId] ?? null;

            $riskBundle = $this->risk->compute(
                close: (float) $bar->close,
                atr14: $atr14,
                swingLow: $swingLow,
                high55: $high55,
            );
            $rrf = $this->scoring->riskRewardFilter($riskBundle['risk_reward'], $minRR);
            $totalBundle = $this->scoring->total($bas['score'], $bcs['score'], $liquidity['pass'], $rrf['pass']);

            $topBrokers = $this->topBrokersForAsset($assetId, $scanDate);

            $reasons = array_filter(array_merge(
                ['BAS: '.implode(', ', $bas['reasons'])],
                ['BCS: '.implode(', ', $bcs['reasons'] ?: ['no breakout signals'])],
                ['liquidity: '.$liquidity['reason']],
                ['risk: '.$rrf['reason']]
            ));

            $rows[] = [
                'asset_id' => $assetId,
                'symbol' => $asset->symbol,
                'scan_date' => $scanDate->toDateString(),
                'version' => $version,
                'close' => (float) $bar->close,
                'net_value' => isset($accWindows[0]) ? (float) ($accWindows[0]['avg_net_norm'] ?? 0) * $turnoverValue : 0.0,
                'vol_ratio_20' => (float) ($features->vol_ratio_20 ?? 0),
                'breakout20' => (bool) $features->breakout20,
                'score_total' => (float) $totalBundle['score_total'],
                'score_bas' => (float) $bas['score'],
                'score_bcs' => (float) $bcs['score'],
                'lf_pass' => (bool) $liquidity['pass'],
                'rrf_pass' => (bool) $rrf['pass'],
                'invalidation_level' => $riskBundle['invalidation_level'],
                'take_profit' => $riskBundle['take_profit'],
                'risk_reward' => $riskBundle['risk_reward'],
                'top_brokers' => $topBrokers,
                'reasons' => array_values($reasons),
                'risk_notes' => $riskBundle['risk_notes'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['score_total'] <=> $a['score_total'];
        });

        $rows = array_slice($rows, 0, $top);
        $persisted = $this->persist($rows, $version);

        return [
            'scan_date' => $scanDate->toDateString(),
            'version' => $version,
            'evaluated' => count($assetMap),
            'persisted' => $persisted,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, string>|null  $symbols
     * @return array<int, Asset> keyed by asset id
     */
    private function loadAssets(?array $symbols): array
    {
        $query = Asset::query()->orderBy('symbol');
        if ($symbols !== null && $symbols !== []) {
            $upper = array_map(static fn ($s) => strtoupper(trim((string) $s)), $symbols);
            $upper = array_values(array_filter($upper, static fn ($s) => $s !== ''));
            $query->whereIn('symbol', $upper);
        }

        return $query->get(['id', 'symbol', 'sector'])->keyBy('id')->all();
    }

    /**
     * @param  array<int, int>  $assetIds
     * @param  array<int, int>  $windows
     * @return array<int, array<int, array<string, mixed>>> asset_id => list of windows
     */
    private function loadAccumulationWindows(array $assetIds, Carbon $scanDate, array $windows): array
    {
        $fixed = BrokerAccumulationWindow::query()
            ->whereIn('asset_id', $assetIds)
            ->whereDate('end_date', $scanDate->toDateString())
            ->whereIn('window_days', $windows)
            ->get();

        // Rows mirroring one imported window at its own length. A three-month
        // broker summary cannot fill a 20-day rollup without claiming flow for
        // days outside it, so it is offered here instead, at window_days = 92.
        // brokerAccumulation() weights by window_days and does not care which
        // lengths exist.
        //
        // end_date <= scanDate, never merely covering it: a window running past
        // the scan date would feed later trading back into it, which flatters a
        // backtest and is the same class of error as the netbs_date bug.
        $native = BrokerAccumulationWindow::query()
            ->whereIn('asset_id', $assetIds)
            ->whereNotNull('source_window_id')
            ->whereDate('end_date', '<=', $scanDate->toDateString())
            ->whereDate('end_date', '>=', $scanDate->copy()->subDays($this->nativeStaleness())->toDateString())
            ->orderByDesc('end_date')
            ->get();

        $out = [];
        $seen = [];

        foreach ($fixed->concat($native) as $row) {
            $id = (int) $row->id;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $out[(int) $row->asset_id][] = [
                'window_days' => (int) $row->window_days,
                'avg_net_norm' => (float) $row->avg_net_norm,
                'top3_net_norm' => (float) $row->top3_net_norm,
                'accdist_score' => (int) $row->accdist_score,
            ];
        }

        return $out;
    }

    /**
     * How far back a native rollup may have ended and still describe the scan.
     */
    private function nativeStaleness(): int
    {
        return $this->windows->maxStalenessDays() ?? 3650;
    }

    /**
     * @param  array<int, Asset>  $assetMap
     * @return array<string, object> symbol => features_daily row
     */
    private function loadFeatures(array $assetMap, Carbon $scanDate): array
    {
        $symbols = array_map(static fn ($a) => $a->symbol, $assetMap);
        if ($symbols === []) {
            return [];
        }

        $rows = DB::table('features_daily')
            ->whereIn('symbol', $symbols)
            ->whereDate('date', $scanDate->toDateString())
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->symbol] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $assetIds
     * @return array<int, object> asset_id => latest bar (close, date)
     */
    private function loadLatestBar(array $assetIds, Carbon $scanDate): array
    {
        $rows = Price::query()
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', '<=', $scanDate->toDateString())
            ->orderByDesc('date')
            ->get(['asset_id', 'date', 'close', 'high', 'low']);

        $out = [];
        foreach ($rows as $row) {
            $assetId = (int) $row->asset_id;
            if (! isset($out[$assetId])) {
                $out[$assetId] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $assetIds
     * @return array<int, float> asset_id => 20-day swing low
     */
    private function loadSwingLows(array $assetIds, Carbon $scanDate): array
    {
        $start = $scanDate->copy()->subDays(20)->toDateString();
        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $scanDate->toDateString())
            ->select('asset_id')
            ->selectRaw('MIN(low) as swing_low')
            ->groupBy('asset_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $low = $row->swing_low;
            if ($low !== null) {
                $out[(int) $row->asset_id] = (float) $low;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{broker: string, net_value: float}>
     */
    private function topBrokersForAsset(int $assetId, Carbon $scanDate): array
    {
        // The window that describes the scan date, which for daily imports is
        // the single-day one and picks out exactly the rows the old
        // whereDate('trade_date', $scanDate) query returned. Reading the window
        // is what lets a ranged import contribute at all: broker_summary_facts
        // now only receives genuinely single-day summaries.
        $window = $this->windows->asOf(
            $assetId,
            $scanDate,
            (string) config('stockbit.defaults.transaction_type'),
        );

        if ($window === null) {
            return [];
        }

        return $window->entries
            ->sortByDesc(static fn ($entry): float => (float) ($entry->net_value ?? 0.0))
            ->take(3)
            ->map(static fn ($entry): array => [
                'broker' => (string) $entry->broker_code,
                'net_value' => (float) ($entry->net_value ?? 0.0),
                'from' => $window->from_date?->toDateString(),
                'to' => $window->to_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persist(array $rows, string $version): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $row['version'] = $version;
            // Eloquent's `array` cast encodes for us; passing arrays here
            // avoids double-encoding the JSON columns.
            $row['top_brokers'] = $row['top_brokers'] ?? [];
            $row['reasons'] = $row['reasons'] ?? [];

            WatchlistScore::updateOrCreate(
                [
                    'scan_date' => $row['scan_date'],
                    'symbol' => $row['symbol'],
                    'version' => $version,
                ],
                $row
            );
            $count++;
        }

        return $count;
    }
}
