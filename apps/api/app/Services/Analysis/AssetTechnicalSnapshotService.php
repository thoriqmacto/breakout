<?php

namespace App\Services\Analysis;

use App\Models\Asset;
use App\Services\AssetMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place a technical metric is calculated.
 *
 * Before this existed the same formulas were written three times -- in
 * AssetMetricsCommand, in AssetController::updateMetrics(), and implicitly in
 * whatever the ranker read out of the `metrics` table -- and they had already
 * drifted: the CLI ranked on ROC13 where the API ranked on PBAS, and the two
 * disagreed on whether ROC13 needed enough bars to be meaningful. "Rank" meant
 * different things depending on which screen you were looking at.
 *
 * Two rules make that impossible to reintroduce:
 *
 *   1. Every metric is computed here, from AssetMetrics, and nowhere else.
 *      Callers are thin: they format, persist or rank what this returns.
 *   2. Every calculation is as-of. A snapshot for 2026-04-01 is built only
 *      from bars dated on or before 2026-04-01, so it is identical whether or
 *      not the database also holds April, May and June. That is what makes
 *      historical scoring and backtesting honest, and it is why the latest
 *      `metrics` row must never be substituted for a historical one.
 *
 * Retrieval is bounded. The longest lookback any of these need is the 55-week
 * high at 275 bars, so LOOKBACK_BARS covers every formula with room to spare
 * and an asset with fifteen years of history costs the same as a new listing.
 */
class AssetTechnicalSnapshotService
{
    /**
     * Bars loaded per asset, counting back from the as-of date.
     *
     * 275 is the deepest requirement (periodHigh(55) takes 55*5 bars); 150 for
     * the trend MA and 66 for ROC13 sit inside it. The remainder is slack so a
     * formula gaining a few bars of lookback does not silently start reading a
     * truncated series.
     */
    public const LOOKBACK_BARS = 300;

    /**
     * Bars ROC13 needs before it means anything.
     *
     * rocWeeks(13) looks back 13*5 = 65 bars and needs one more to compare
     * against, so 66 is the exact floor. The CLI used to publish a ROC13 for an
     * asset with twenty bars, where AssetMetrics returns a flat 0.0 for want of
     * a comparison point -- and 0.0 then ranked that asset above every
     * genuinely falling one. Below this the honest answer is null.
     */
    private const ROC13_MIN_BARS = 66;

    /**
     * One asset's technical picture as of a date, or null when it has no bar
     * at or before that date.
     */
    public function snapshotForAssetAsOf(Asset $asset, ?Carbon $asOf = null): ?AssetTechnicalSnapshot
    {
        $snapshots = $this->snapshotsForAssetsAsOf([$asset], $asOf);

        return $snapshots[(int) $asset->id] ?? null;
    }

    /**
     * Snapshots for many assets in one pass, keyed by asset id.
     *
     * Bars are fetched per asset because each needs its own most-recent N --
     * a single windowed query would be nicer but is not portable across the
     * SQLite used in tests and the MariaDB used in production. What this does
     * avoid is the shape that actually hurts: loading an asset's entire price
     * history, and re-querying the bar count once per row.
     *
     * @param  iterable<int, Asset>  $assets
     * @return array<int, AssetTechnicalSnapshot>
     */
    public function snapshotsForAssetsAsOf(iterable $assets, ?Carbon $asOf = null): array
    {
        $assets = $assets instanceof Collection ? $assets->all() : (is_array($assets) ? $assets : iterator_to_array($assets));

        if ($assets === []) {
            return [];
        }

        $asOfDate = ($asOf ? $asOf->copy() : Carbon::now())->startOfDay()->toDateString();
        $assetIds = array_map(static fn (Asset $asset): int => (int) $asset->id, $assets);
        $barCounts = $this->barCounts($assetIds, $asOfDate);

        $out = [];

        foreach ($assets as $asset) {
            $assetId = (int) $asset->id;
            $rows = $this->loadBars($assetId, $asOfDate);

            if ($rows === []) {
                continue;
            }

            $snapshot = $this->build($asset, $asOfDate, $rows, $barCounts[$assetId] ?? count($rows));

            if ($snapshot !== null) {
                $out[$assetId] = $snapshot;
            }
        }

        return $out;
    }

    /**
     * Structural ordering over a set of snapshots, strongest first.
     *
     * The single implementation of "rank" for structural strength. Ties break
     * on symbol so the same universe always produces the same list, which
     * matters as soon as anyone compares today's rank to yesterday's.
     *
     * @param  array<int, AssetTechnicalSnapshot>  $snapshots
     * @return array<int, AssetTechnicalSnapshot> re-indexed, rank order
     */
    public function rankStructurally(array $snapshots): array
    {
        $ordered = array_values($snapshots);

        usort($ordered, static function (AssetTechnicalSnapshot $a, AssetTechnicalSnapshot $b): int {
            $result = $b->structuralSortKey() <=> $a->structuralSortKey();

            return $result === 0 ? ($a->symbol <=> $b->symbol) : $result;
        });

        return $ordered;
    }

    /**
     * symbol => 1-based structural rank.
     *
     * @param  array<int, AssetTechnicalSnapshot>  $snapshots
     * @return array<string, int>
     */
    public function structuralRanks(array $snapshots): array
    {
        $ranks = [];

        foreach ($this->rankStructurally($snapshots) as $index => $snapshot) {
            $ranks[$snapshot->symbol] = $index + 1;
        }

        return $ranks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Ascending by date.
     */
    private function build(Asset $asset, string $requestedAsOf, array $rows, int $bars): ?AssetTechnicalSnapshot
    {
        $last = $rows[array_key_last($rows)];
        $metrics = new AssetMetrics($rows);
        $warnings = [];

        $close = (float) $last['close'];
        $high = (float) $last['high'];
        $low = (float) $last['low'];

        $ma50 = $this->positiveOrNull($metrics->movingAverage(50));
        $ma100 = $this->positiveOrNull($metrics->movingAverage(100));
        $ma150 = $this->positiveOrNull($metrics->movingAverageTradingDays(150));
        $high20 = $this->positiveOrNull($metrics->periodHigh(20));
        $high55 = $this->positiveOrNull($metrics->periodHigh(55));
        $atr14 = $this->positiveOrNull($metrics->atr(14));

        // Reported only where there is a real comparison bar behind it. Below
        // the threshold AssetMetrics returns 0.0, which reads as "flat" rather
        // than "unknown" and ranks accordingly.
        $roc13 = null;
        if (count($rows) >= self::ROC13_MIN_BARS) {
            $roc13 = round($metrics->rocWeeks(13), 4);
        } else {
            $warnings[] = 'roc13 unavailable: fewer than '.self::ROC13_MIN_BARS.' bars of history';
        }

        $avgVol20 = $this->positiveOrNull($metrics->averageVolume(20));
        $volume = (float) ($last['volume'] ?? 0.0);
        $volRatio20 = $avgVol20 !== null ? round($volume / $avgVol20, 6) : null;

        // The breakout reference: the highest high of the twenty sessions
        // *before* this one, matching FeatureExtractionService::breakout20 so a
        // planned trigger and a stored feature cannot disagree.
        $priorHigh20 = null;
        if (count($rows) >= 21) {
            $priorHighs = array_map(
                static fn (array $bar): float => (float) $bar['high'],
                array_slice($rows, -21, 20),
            );
            $priorHigh20 = max($priorHighs);
        }

        $swingLows = array_map(
            static fn (array $bar): float => (float) $bar['low'],
            array_slice($rows, -20),
        );
        $swingLow20 = $swingLows === [] ? null : min($swingLows);

        // The 55-session breakout reference, built the same way as the
        // 20-session one: the highest high of the sessions *before* this one,
        // never including the bar being classified.
        $priorHigh55 = null;
        if (count($rows) >= 56) {
            $priorHigh55 = max(array_map(
                static fn (array $bar): float => (float) $bar['high'],
                array_slice($rows, -56, 55),
            ));
        }

        $ema20 = $this->positiveOrNull($metrics->exponentialMovingAverage(20));
        $ema50 = $this->positiveOrNull($metrics->exponentialMovingAverage(50));

        $prevClose = count($rows) >= 2 ? (float) $rows[count($rows) - 2]['close'] : null;
        $gapPct = ($prevClose !== null && $prevClose > 0)
            ? round(((float) $last['open'] - $prevClose) / $prevClose * 100.0, 4)
            : null;

        $compression = $this->compression($rows, $atr14, $close);

        $range = $high - $low;
        $closePos = $range > 0 ? round(($close - $low) / $range, 6) : null;

        if ($bars < 21) {
            $warnings[] = 'thin history: '.$bars.' bar(s) available';
        }

        return new AssetTechnicalSnapshot(
            assetId: (int) $asset->id,
            symbol: (string) $asset->symbol,
            name: $asset->name,
            sector: $asset->sector,
            requestedAsOf: $requestedAsOf,
            asOfDate: (string) $last['date'],
            bars: $bars,
            open: (float) $last['open'],
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
            ma50: $ma50 === null ? null : round($ma50, 4),
            ma100: $ma100 === null ? null : round($ma100, 4),
            ma150: $ma150 === null ? null : round($ma150, 4),
            uptrend: $ma150 !== null && $close > $ma150,
            high20w: $high20 === null ? null : round($high20, 4),
            high55w: $high55 === null ? null : round($high55, 4),
            atr14: $atr14 === null ? null : round($atr14, 4),
            roc13: $roc13,
            avgVol20: $avgVol20 === null ? null : round($avgVol20, 4),
            volRatio20: $volRatio20,
            closeVsHigh20: $high20 !== null && $high20 > 0 ? round($close / $high20, 6) : null,
            closeVsHigh55: $high55 !== null && $high55 > 0 ? round($close / $high55, 6) : null,
            priorHigh20: $priorHigh20 === null ? null : round($priorHigh20, 4),
            swingLow20: $swingLow20 === null ? null : round($swingLow20, 4),
            closePos: $closePos,
            ema20: $ema20 === null ? null : round($ema20, 4),
            ema50: $ema50 === null ? null : round($ema50, 4),
            priorHigh55: $priorHigh55 === null ? null : round($priorHigh55, 4),
            prevClose: $prevClose === null ? null : round($prevClose, 4),
            gapPct: $gapPct,
            compression: $compression,
            warnings: $warnings,
        );
    }

    /**
     * Whether volatility is compressing: today's ATR as a percentage of close
     * sitting below the mean of the same measure over the previous ten
     * sessions.
     *
     * Deliberately the definition FeatureExtractionService already uses for
     * its `compression` flag, so the stored feature and the snapshot cannot
     * disagree about the same session. Computed over the last eleven
     * positions only -- an ATR at each needs fourteen bars behind it, and
     * measuring further back would cost a great deal to answer a question
     * nobody asked.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function compression(array $rows, ?float $atr14, float $close): ?bool
    {
        if ($atr14 === null || $close <= 0 || count($rows) < 25) {
            return null;
        }

        $history = [];

        for ($offset = 1; $offset <= 10; $offset++) {
            $slice = array_slice($rows, 0, count($rows) - $offset);

            if (count($slice) < 15) {
                return null;
            }

            $priorClose = (float) $slice[count($slice) - 1]['close'];
            $priorAtr = (new AssetMetrics($slice))->atr(14);

            if ($priorClose <= 0 || $priorAtr <= 0) {
                return null;
            }

            $history[] = $priorAtr / $priorClose;
        }

        return ($atr14 / $close) < (array_sum($history) / count($history));
    }

    /**
     * The most recent LOOKBACK_BARS bars at or before $asOfDate, ascending.
     *
     * Compared as dates, not strings: a bar written through the model stores
     * "YYYY-MM-DD 00:00:00" where the query builder stores a plain date, and a
     * raw string comparison silently drops the as-of day itself in the first
     * case -- which would build every snapshot from yesterday.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadBars(int $assetId, string $asOfDate): array
    {
        $rows = DB::table('price_bars')
            ->where('asset_id', $assetId)
            ->whereDate('date', '<=', $asOfDate)
            ->orderByDesc('date')
            ->limit(self::LOOKBACK_BARS)
            ->get(['date', 'open', 'high', 'low', 'close', 'volume']);

        $bars = [];

        foreach ($rows->reverse() as $row) {
            $bars[] = [
                'date' => Carbon::parse((string) $row->date)->toDateString(),
                'open' => (float) ($row->open ?? 0.0),
                'high' => (float) ($row->high ?? 0.0),
                'low' => (float) ($row->low ?? 0.0),
                'close' => (float) ($row->close ?? 0.0),
                'volume' => (float) ($row->volume ?? 0.0),
            ];
        }

        return $bars;
    }

    /**
     * Total bars each asset holds at or before the as-of date.
     *
     * Reported separately from the bounded window so "bars" keeps meaning the
     * asset's real depth of history rather than however many this service
     * chose to load. One grouped query for the whole batch, not one per asset.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, int>
     */
    private function barCounts(array $assetIds, string $asOfDate): array
    {
        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', '<=', $asOfDate)
            ->select('asset_id')
            ->selectRaw('COUNT(*) as bar_count')
            ->groupBy('asset_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->asset_id] = (int) $row->bar_count;
        }

        return $counts;
    }

    private function positiveOrNull(float $value): ?float
    {
        return $value > 0 ? $value : null;
    }
}
