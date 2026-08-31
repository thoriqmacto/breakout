<?php

namespace App\Services\Analysis;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\Metric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns canonical snapshots into the shape the metrics table, the CLI table
 * and the metrics API all speak.
 *
 * Three things live here and nowhere else:
 *
 *   - the presentation rounding, so the CLI and the API cannot print the same
 *     figure to different precision (they used to: the volume ratio was five
 *     decimal places on the command line and two through the API);
 *   - the as-of lookup of the two non-technical columns the metrics view
 *     carries, PBAS and BAVG, which come from features_daily and the bandar
 *     detector rather than from price bars;
 *   - the write into `metrics`.
 *
 * `metrics` stays what it has always been: a cache of the latest snapshot, one
 * row per asset, convenient for a list view. It is never historical truth --
 * scoring a past date reads a snapshot built for that date, because this row
 * has no idea which session it describes.
 */
class AssetMetricProjector
{
    /**
     * How far back the PBAS lookup will reach for a symbol with no feature row
     * on the as-of date itself.
     *
     * features_daily is written per session, so a gap means the extraction has
     * been failing. Reaching back a month keeps a holiday or a single missed
     * night from blanking the column; reaching back further would present a
     * stale score as current.
     */
    private const PBAS_LOOKBACK_DAYS = 30;

    public function __construct(private readonly AssetTechnicalSnapshotService $snapshots) {}

    /**
     * Project a set of assets as of a date, ranked structurally.
     *
     * @param  iterable<int, Asset>  $assets
     * @return array<int, array<string, mixed>> rank order, 1-based `structural_rank`
     */
    public function project(iterable $assets, ?Carbon $asOf = null): array
    {
        $snapshots = $this->snapshots->snapshotsForAssetsAsOf($assets, $asOf);

        if ($snapshots === []) {
            return [];
        }

        $ordered = $this->snapshots->rankStructurally($snapshots);

        $symbols = array_map(static fn (AssetTechnicalSnapshot $s): string => $s->symbol, $ordered);
        $assetIds = array_map(static fn (AssetTechnicalSnapshot $s): int => $s->assetId, $ordered);

        $pbas = $this->pbasBySymbol($symbols, $ordered);
        $bavg = $this->bavgByAsset($assetIds, $ordered);

        $rows = [];

        foreach ($ordered as $index => $snapshot) {
            $rows[] = $this->shape($snapshot, $index + 1, $pbas[$snapshot->symbol] ?? null, $bavg[$snapshot->assetId] ?? null);
        }

        return $rows;
    }

    /**
     * Write projected rows into the latest-snapshot cache.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function persist(array $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            Metric::updateOrCreate(
                ['asset_id' => $row['asset_id']],
                [
                    'symbol' => $row['symbol'],
                    'name' => $row['name'],
                    'close' => $row['close'],
                    'ma50' => $row['ma50'],
                    'ma100' => $row['ma100'],
                    'high20' => $row['high20'],
                    'high55' => $row['high55'],
                    'atr14' => $row['atr14'],
                    'roc13' => $row['roc13'],
                    'avg_vol20' => $row['avg_vol20'],
                    'vol_vs_avg20' => $row['vol_vs_avg20'],
                    'close_vs_high20' => $row['close_vs_high20'],
                    'close_vs_high55' => $row['close_vs_high55'],
                    'uptrend' => $row['uptrend'],
                    'bars' => $row['bars'],
                    'pbas' => $row['pbas'],
                    'bavg' => $row['bavg'],
                    'sort_uptrend' => $row['sort_uptrend'],
                    'sort_roc13' => $row['sort_roc13'],
                    'sort_close_vs_high55' => $row['sort_close_vs_high55'],
                    'sort_close_vs_high20' => $row['sort_close_vs_high20'],
                    'sort_vol_vs_avg20' => $row['sort_vol_vs_avg20'],
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * Drop cached rows for assets that no longer have any price history.
     *
     * @param  array<int, int>  $assetIds  Assets considered in this pass.
     * @param  array<int, array<string, mixed>>  $rows  What was projected for them.
     */
    public function forgetMissing(array $assetIds, array $rows): int
    {
        $projected = array_map(static fn (array $row): int => (int) $row['asset_id'], $rows);
        $stale = array_values(array_diff($assetIds, $projected));

        if ($stale === []) {
            return 0;
        }

        return Metric::query()->whereIn('asset_id', $stale)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AssetTechnicalSnapshot $snapshot, int $structuralRank, ?int $pbas, ?float $bavg): array
    {
        $key = $snapshot->structuralSortKey();

        return [
            'structural_rank' => $structuralRank,
            'asset_id' => $snapshot->assetId,
            'symbol' => $snapshot->symbol,
            'name' => $snapshot->name,
            'sector' => $snapshot->sector,
            'as_of_date' => $snapshot->asOfDate,
            'close' => round($snapshot->close, 2),
            'ma50' => $snapshot->ma50 === null ? null : round($snapshot->ma50),
            'ma100' => $snapshot->ma100 === null ? null : round($snapshot->ma100),
            'ma150' => $snapshot->ma150 === null ? null : round($snapshot->ma150),
            'high20' => $snapshot->high20w === null ? null : round($snapshot->high20w),
            'high55' => $snapshot->high55w === null ? null : round($snapshot->high55w),
            'atr14' => $snapshot->atr14 === null ? null : round($snapshot->atr14),
            'roc13' => $snapshot->roc13 === null ? null : round($snapshot->roc13, 2),
            'avg_vol20' => $snapshot->avgVol20 === null ? null : round($snapshot->avgVol20),
            'vol_vs_avg20' => $snapshot->volRatio20 === null ? null : round($snapshot->volRatio20, 4),
            'close_vs_high20' => $snapshot->closeVsHigh20 === null ? null : round($snapshot->closeVsHigh20, 4),
            'close_vs_high55' => $snapshot->closeVsHigh55 === null ? null : round($snapshot->closeVsHigh55, 4),
            'uptrend' => $snapshot->uptrend,
            'bars' => $snapshot->bars,
            'pbas' => $pbas,
            'bavg' => $bavg,
            'warnings' => $snapshot->warnings,
            // Persisted so the cache can be ordered in SQL by exactly the key
            // rankStructurally() sorts on in PHP.
            'sort_uptrend' => (int) $key[0],
            'sort_roc13' => (float) $key[1],
            'sort_close_vs_high55' => (float) $key[2],
            'sort_close_vs_high20' => (float) $key[3],
            'sort_vol_vs_avg20' => (float) $key[4],
        ];
    }

    /**
     * Latest PBAS at or before each snapshot's own session.
     *
     * @param  array<int, string>  $symbols
     * @param  array<int, AssetTechnicalSnapshot>  $snapshots
     * @return array<string, int>
     */
    private function pbasBySymbol(array $symbols, array $snapshots): array
    {
        if ($symbols === []) {
            return [];
        }

        $latestAsOf = max(array_map(static fn (AssetTechnicalSnapshot $s): string => $s->asOfDate, $snapshots));
        $floor = Carbon::parse($latestAsOf)->subDays(self::PBAS_LOOKBACK_DAYS)->toDateString();

        $rows = DB::table('features_daily')
            ->whereIn('symbol', $symbols)
            ->whereDate('date', '<=', $latestAsOf)
            ->whereDate('date', '>=', $floor)
            ->orderByDesc('date')
            ->get(['symbol', 'date', 'pbas']);

        $asOfBySymbol = [];
        foreach ($snapshots as $snapshot) {
            $asOfBySymbol[$snapshot->symbol] = $snapshot->asOfDate;
        }

        $out = [];

        foreach ($rows as $row) {
            $symbol = (string) $row->symbol;

            if (isset($out[$symbol]) || $row->pbas === null) {
                continue;
            }

            // Each symbol answers against its own session, which can trail the
            // batch's newest when an asset stopped trading.
            if (Carbon::parse((string) $row->date)->toDateString() > ($asOfBySymbol[$symbol] ?? $latestAsOf)) {
                continue;
            }

            $out[$symbol] = (int) $row->pbas;
        }

        return $out;
    }

    /**
     * The broker average price of the window covering each snapshot's session.
     *
     * @param  array<int, int>  $assetIds
     * @param  array<int, AssetTechnicalSnapshot>  $snapshots
     * @return array<int, float>
     */
    private function bavgByAsset(array $assetIds, array $snapshots): array
    {
        if ($assetIds === []) {
            return [];
        }

        $asOfByAsset = [];
        foreach ($snapshots as $snapshot) {
            $asOfByAsset[$snapshot->assetId] = $snapshot->asOfDate;
        }

        $earliest = min($asOfByAsset);
        $latest = max($asOfByAsset);

        $rows = BandarDetectorSummary::query()
            ->whereIn('asset_id', $assetIds)
            ->whereNotNull('average_price')
            ->whereDate('from_date', '<=', $latest)
            ->whereDate('to_date', '>=', $earliest)
            ->orderByDesc('from_date')
            ->get(['asset_id', 'from_date', 'to_date', 'average_price']);

        $out = [];

        foreach ($rows as $row) {
            $assetId = (int) $row->asset_id;

            if (isset($out[$assetId])) {
                continue;
            }

            $asOf = $asOfByAsset[$assetId] ?? null;

            if ($asOf === null) {
                continue;
            }

            $from = Carbon::parse((string) $row->from_date)->toDateString();
            $to = Carbon::parse((string) $row->to_date)->toDateString();

            if ($from <= $asOf && $to >= $asOf) {
                $out[$assetId] = (float) $row->average_price;
            }
        }

        return $out;
    }
}
