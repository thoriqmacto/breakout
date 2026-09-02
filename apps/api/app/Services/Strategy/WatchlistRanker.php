<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\BrokerAccumulationWindow;
use App\Models\WatchlistScore;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates the per-symbol scoring pipeline:
 *   - Pull broker accumulation windows + features_daily + an as-of technical
 *     snapshot
 *   - Apply BAS / BCS / liquidity / risk-reward scoring
 *   - Persist to watchlist_scores (idempotent on (scan_date, symbol, version))
 *   - Return the ranked list with explainable rationale
 *
 * Pure-PHP scoring lives in StrategyScoringService and RiskCalculator.
 * This class is responsible for IO + composition only.
 *
 * The technical inputs -- ATR14, the 55-week high, the swing low, the close --
 * come from AssetTechnicalSnapshotService built for the scan date. They used to
 * be read out of the `metrics` table, which holds exactly one row per asset
 * describing the *latest* session whatever date was being scored. Scoring
 * 2026-04-01 therefore stopped it against today's ATR and today's 55-week high:
 * information that did not exist on the day, quietly flattering every backfill
 * and every backtest built on the result. `metrics` remains a cache of the
 * latest snapshot and is never consulted here.
 *
 * Every evaluated asset is persisted. `top` bounds what the caller is handed
 * back for display, not what research can later measure -- keeping only the
 * best thirty rows made every historical statistic a statement about an
 * already-selected population while reading like one about the universe.
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
        private readonly AssetTechnicalSnapshotService $snapshots,
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

        // One batched pass, bounded per asset, computed strictly from bars at
        // or before the scan date.
        $snapshotByAsset = $this->snapshots->snapshotsForAssetsAsOf($assetMap, $scanDate);
        $structuralRanks = $this->snapshots->structuralRanks($snapshotByAsset);

        $rows = [];
        foreach ($assetMap as $assetId => $asset) {
            $features = $featuresBySymbol[$asset->symbol] ?? null;
            $snapshot = $snapshotByAsset[$assetId] ?? null;
            $accWindows = $windowsByAsset[$assetId] ?? [];

            if ($snapshot === null || $features === null) {
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

            $riskBundle = $this->risk->compute(
                close: $snapshot->close,
                atr14: $snapshot->atr14,
                swingLow: $snapshot->swingLow20,
                high55: $snapshot->high55w,
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
                'close' => $snapshot->close,
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
                'snapshot' => $snapshot,
                'structural_rank' => $structuralRanks[$asset->symbol] ?? null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $result = $b['score_total'] <=> $a['score_total'];

            return $result === 0 ? ($a['symbol'] <=> $b['symbol']) : $result;
        });

        // Everything evaluated is persisted; only the caller's view is capped.
        $written = $this->persist($rows, $version);

        return [
            'scan_date' => $scanDate->toDateString(),
            'version' => $version,
            'evaluated' => count($assetMap),
            'scored' => count($rows),
            'persisted' => $written['persisted'],
            'failed' => $written['failed'],
            'rows' => array_slice($rows, 0, $top),
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
    /**
     * Write the scored rows, one symbol at a time.
     *
     * A row that will not store is isolated rather than allowed to abort the
     * pass. Rows are sorted by score before this runs, so an exception part
     * way through used to persist everything above the offending symbol,
     * discard everything below it, and fail the whole step -- and which
     * symbols survived depended on where the bad one happened to rank.
     *
     * The failures are returned and reported, never swallowed: one symbol
     * silently missing from the watchlist is the outcome this is meant to
     * prevent, not the one it should cause.
     *
     * @return array{persisted: int, failed: array<string, string>}
     */
    private function persist(array $rows, string $version): array
    {
        $count = 0;
        $failed = [];

        foreach ($rows as $row) {
            // Carried on the row for the caller, not stored: the snapshot is
            // an object, and structural rank is a property of the universe
            // being ranked rather than of this score.
            unset($row['snapshot'], $row['structural_rank']);

            $row['version'] = $version;
            // Eloquent's `array` cast encodes for us; passing arrays here
            // avoids double-encoding the JSON columns.
            $row['top_brokers'] = $row['top_brokers'] ?? [];
            $row['reasons'] = $row['reasons'] ?? [];

            try {
                WatchlistScore::updateOrCreate(
                    [
                        'scan_date' => $row['scan_date'],
                        'symbol' => $row['symbol'],
                        'version' => $version,
                    ],
                    $row
                );
                $count++;
            } catch (Throwable $exception) {
                $failed[$row['symbol']] = $exception->getMessage();
            }
        }

        return ['persisted' => $count, 'failed' => $failed];
    }
}
