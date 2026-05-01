<?php

namespace App\Services\Strategy;

use App\Models\TradingDay;
use App\Models\WatchlistScore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replay persisted watchlist_scores against forward N-day returns to
 * evaluate score lift. Reads-only over watchlist_scores + price_bars +
 * trading_days; never writes.
 *
 * Output is grouped by score bucket and by filter combination so the
 * caller can see whether higher scores or filter passes actually lead
 * to higher hit rates than the population baseline. The report is
 * deliberately conservative — no curve-fitting, no per-symbol
 * tuning, just bucket statistics with sample counts so a small
 * sample is visible at a glance.
 */
class WatchlistBacktester
{
    public const DEFAULT_HORIZONS = [5, 10, 20];
    public const DEFAULT_TARGET_PCT = 0.035;

    /**
     * @param array<int, int> $horizons
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   version: string,
     *   target_pct: float,
     *   horizons: array<int, int>,
     *   sample_size: int,
     *   baseline: array<int, array{horizon: int, hit_rate: float, avg_return: float, n: int}>,
     *   buckets: array<int, array{
     *     bucket: string,
     *     n: int,
     *     by_horizon: array<int, array{horizon: int, hit_rate: float, avg_return: float, lift_vs_baseline: float|null}>,
     *   }>,
     *   filter_combos: array<int, array{
     *     combo: string,
     *     n: int,
     *     by_horizon: array<int, array{horizon: int, hit_rate: float, avg_return: float, lift_vs_baseline: float|null}>,
     *   }>,
     * }
     */
    public function backtest(
        Carbon $from,
        Carbon $to,
        string $version = 'v1',
        array $horizons = self::DEFAULT_HORIZONS,
        float $targetPct = self::DEFAULT_TARGET_PCT,
        ?int $limitPerDay = null
    ): array {
        $tradingDays = $this->loadTradingDays();
        if ($tradingDays === []) {
            return $this->emptyReport($from, $to, $version, $horizons, $targetPct);
        }

        $tradingDayIndex = array_flip($tradingDays);

        $scoreRows = $this->loadScores($from, $to, $version, $limitPerDay);
        if ($scoreRows->isEmpty()) {
            return $this->emptyReport($from, $to, $version, $horizons, $targetPct);
        }

        // Pull every relevant price bar in one pass to avoid N+1.
        [$earliestNeeded, $latestNeeded] = $this->priceWindow($scoreRows, $tradingDays, $tradingDayIndex, $horizons);
        $bars = $this->loadBars($scoreRows->pluck('asset_id')->unique()->values()->all(), $earliestNeeded, $latestNeeded);

        $observations = [];
        foreach ($scoreRows as $row) {
            $assetId = (int) $row->asset_id;
            $scanDate = $this->normalizeDate($row->scan_date);
            if ($scanDate === null) {
                continue;
            }

            if (!isset($tradingDayIndex[$scanDate])) {
                continue;
            }
            $entryIndex = $tradingDayIndex[$scanDate];
            $entryClose = $bars[$assetId][$scanDate]->close ?? null;
            if ($entryClose === null || $entryClose <= 0) {
                continue;
            }

            $perHorizon = [];
            foreach ($horizons as $h) {
                $exitIndex = $entryIndex + $h;
                if (!isset($tradingDays[$exitIndex])) {
                    continue;
                }
                $exitDate = $tradingDays[$exitIndex];
                $exitClose = $bars[$assetId][$exitDate]->close ?? null;
                if ($exitClose === null || $exitClose <= 0) {
                    continue;
                }

                $ret = ($exitClose - $entryClose) / $entryClose;
                $perHorizon[$h] = [
                    'return' => $ret,
                    'hit' => $ret >= $targetPct,
                ];
            }

            if ($perHorizon === []) {
                continue;
            }

            $observations[] = [
                'symbol' => $row->symbol,
                'scan_date' => $scanDate,
                'score_total' => (float) $row->score_total,
                'lf_pass' => (bool) $row->lf_pass,
                'rrf_pass' => (bool) $row->rrf_pass,
                'per_horizon' => $perHorizon,
            ];
        }

        $baseline = $this->aggregate($observations, $horizons, static fn () => true);

        $buckets = [];
        foreach ($this->bucketDefinitions() as [$label, $low, $high]) {
            $aggregated = $this->aggregate(
                $observations,
                $horizons,
                static fn (array $o) => $o['score_total'] >= $low && $o['score_total'] < $high,
                $baseline
            );
            $buckets[] = [
                'bucket' => $label,
                'n' => $this->sampleSize($observations, static fn (array $o) => $o['score_total'] >= $low && $o['score_total'] < $high),
                'by_horizon' => $aggregated,
            ];
        }

        $filterCombos = [];
        foreach ($this->filterCombos() as [$label, $predicate]) {
            $aggregated = $this->aggregate($observations, $horizons, $predicate, $baseline);
            $filterCombos[] = [
                'combo' => $label,
                'n' => $this->sampleSize($observations, $predicate),
                'by_horizon' => $aggregated,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'version' => $version,
            'target_pct' => $targetPct,
            'horizons' => $horizons,
            'sample_size' => count($observations),
            'baseline' => $baseline,
            'buckets' => $buckets,
            'filter_combos' => $filterCombos,
        ];
    }

    /**
     * @return array<int, string> ordered list of trading day ISO dates
     */
    private function loadTradingDays(): array
    {
        return TradingDay::query()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => $this->normalizeDate($d))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, WatchlistScore>
     */
    private function loadScores(Carbon $from, Carbon $to, string $version, ?int $limitPerDay)
    {
        $query = WatchlistScore::query()
            ->where('version', $version)
            ->whereDate('scan_date', '>=', $from->toDateString())
            ->whereDate('scan_date', '<=', $to->toDateString());

        if ($limitPerDay === null) {
            return $query->get();
        }

        $rows = $query->orderBy('scan_date')->orderByDesc('score_total')->get();
        $perDayCounts = [];
        return $rows->filter(function (WatchlistScore $row) use (&$perDayCounts, $limitPerDay): bool {
            $scanDate = $this->normalizeDate($row->scan_date);
            if ($scanDate === null) {
                return false;
            }
            $perDayCounts[$scanDate] ??= 0;
            if ($perDayCounts[$scanDate] >= $limitPerDay) {
                return false;
            }
            $perDayCounts[$scanDate]++;
            return true;
        })->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, WatchlistScore> $rows
     * @param array<int, string> $tradingDays
     * @param array<string, int> $tradingDayIndex
     * @param array<int, int> $horizons
     * @return array{0: string, 1: string}
     */
    private function priceWindow($rows, array $tradingDays, array $tradingDayIndex, array $horizons): array
    {
        $maxHorizon = max($horizons);
        $minDate = null;
        $maxDate = null;
        foreach ($rows as $row) {
            $scanDate = $this->normalizeDate($row->scan_date);
            if ($scanDate === null || !isset($tradingDayIndex[$scanDate])) {
                continue;
            }
            if ($minDate === null || $scanDate < $minDate) {
                $minDate = $scanDate;
            }
            $exitIndex = $tradingDayIndex[$scanDate] + $maxHorizon;
            $exitDate = $tradingDays[$exitIndex] ?? end($tradingDays);
            if ($maxDate === null || $exitDate > $maxDate) {
                $maxDate = $exitDate;
            }
        }

        return [(string) ($minDate ?? '1970-01-01'), (string) ($maxDate ?? '1970-01-01')];
    }

    /**
     * @param array<int, int> $assetIds
     * @return array<int, array<string, object>>
     */
    private function loadBars(array $assetIds, string $minDate, string $maxDate): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', '>=', $minDate)
            ->whereDate('date', '<=', $maxDate)
            ->select('asset_id', 'date', 'close')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate($row->date);
            if ($date === null) {
                continue;
            }
            $row->close = $row->close === null ? null : (float) $row->close;
            $out[(int) $row->asset_id][$date] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @param array<int, int> $horizons
     * @param callable(array<string, mixed>): bool $predicate
     * @param array<int, array{horizon: int, hit_rate: float, avg_return: float, n: int}>|null $baseline
     * @return array<int, array{horizon: int, hit_rate: float, avg_return: float, lift_vs_baseline: float|null, n: int}>
     */
    private function aggregate(array $observations, array $horizons, callable $predicate, ?array $baseline = null): array
    {
        $out = [];
        foreach ($horizons as $h) {
            $hits = 0;
            $sumReturn = 0.0;
            $count = 0;
            foreach ($observations as $obs) {
                if (!$predicate($obs)) {
                    continue;
                }
                if (!isset($obs['per_horizon'][$h])) {
                    continue;
                }
                $count++;
                if ($obs['per_horizon'][$h]['hit']) {
                    $hits++;
                }
                $sumReturn += $obs['per_horizon'][$h]['return'];
            }

            $hitRate = $count > 0 ? $hits / $count : 0.0;
            $avgReturn = $count > 0 ? $sumReturn / $count : 0.0;
            $lift = null;
            if ($baseline !== null) {
                $baselineHit = $this->lookupBaselineHit($baseline, $h);
                $lift = $baselineHit === null ? null : $hitRate - $baselineHit;
            }

            $out[] = [
                'horizon' => $h,
                'hit_rate' => round($hitRate, 4),
                'avg_return' => round($avgReturn, 6),
                'n' => $count,
                'lift_vs_baseline' => $lift === null ? null : round($lift, 4),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array{horizon: int, hit_rate: float, avg_return: float, n: int}> $baseline
     */
    private function lookupBaselineHit(array $baseline, int $horizon): ?float
    {
        foreach ($baseline as $row) {
            if ((int) ($row['horizon'] ?? -1) === $horizon) {
                return (float) ($row['hit_rate'] ?? 0.0);
            }
        }

        return null;
    }

    /**
     * @return array<int, array{0: string, 1: float, 2: float}>
     */
    private function bucketDefinitions(): array
    {
        return [
            ['0–25', 0.0, 25.0],
            ['25–50', 25.0, 50.0],
            ['50–75', 50.0, 75.0],
            ['75–100', 75.0, 100.0001],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: callable(array<string, mixed>): bool}>
     */
    private function filterCombos(): array
    {
        return [
            ['LF=fail · RRF=fail', static fn (array $o) => !$o['lf_pass'] && !$o['rrf_pass']],
            ['LF=pass · RRF=fail', static fn (array $o) => $o['lf_pass'] && !$o['rrf_pass']],
            ['LF=fail · RRF=pass', static fn (array $o) => !$o['lf_pass'] && $o['rrf_pass']],
            ['LF=pass · RRF=pass', static fn (array $o) => $o['lf_pass'] && $o['rrf_pass']],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @param callable(array<string, mixed>): bool $predicate
     */
    private function sampleSize(array $observations, callable $predicate): int
    {
        $n = 0;
        foreach ($observations as $obs) {
            if ($predicate($obs)) {
                $n++;
            }
        }

        return $n;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Carbon || $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $str = (string) $value;
        if ($str === '') {
            return null;
        }
        try {
            return Carbon::parse($str)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, int> $horizons
     */
    private function emptyReport(Carbon $from, Carbon $to, string $version, array $horizons, float $targetPct): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'version' => $version,
            'target_pct' => $targetPct,
            'horizons' => $horizons,
            'sample_size' => 0,
            'baseline' => [],
            'buckets' => [],
            'filter_combos' => [],
        ];
    }
}
