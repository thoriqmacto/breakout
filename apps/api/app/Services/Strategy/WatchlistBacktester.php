<?php

namespace App\Services\Strategy;

use App\Models\TradingDay;
use App\Models\WatchlistScore;
use App\Services\Execution\ExecutionPlanner;
use App\Services\IdxTicks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replay persisted watchlist_scores against forward returns to evaluate score
 * lift. Reads-only over watchlist_scores + price_bars + trading_days; never
 * writes.
 *
 * Entry is on the session *after* the signal. This is the correction that
 * matters most: the scores are computed from session T's completed bar, so
 * they do not exist until T has closed, and the previous version of this
 * backtester entered at T's close anyway. Every statistic it produced was
 * therefore measured from a price that was already unavailable when the signal
 * was generated -- an edge borrowed from the future, distributed evenly across
 * every bucket, which is exactly the shape that makes a strategy look mildly
 * profitable and trade unprofitably.
 *
 * Two entry modes:
 *
 *   next_open         Fill at T+1's open. The simplest honest assumption, and
 *                     the floor any other mode has to beat.
 *   breakout_trigger  Fill only if T+1 actually trades through a level derived
 *                     from T. If it never touches the trigger there is no
 *                     trade; if it opens above the trigger the fill is the
 *                     open, never the trigger, because you cannot buy below
 *                     the day's first print.
 *
 * A fill is not automatically a trade. The risk levels come from the signal
 * session, so a gap moves the entry without moving the stop, and a setup that
 * cleared its minimum risk/reward at T can fail it at the price actually paid.
 * Those are counted as rejections rather than quietly kept.
 *
 * Output is grouped by score bucket and by filter combination so the caller
 * can see whether higher scores or filter passes actually lead to higher hit
 * rates than the population baseline. Deliberately conservative -- no
 * curve-fitting, no per-symbol tuning, just bucket statistics with sample
 * counts so a small sample is visible at a glance.
 */
class WatchlistBacktester
{
    public const DEFAULT_HORIZONS = [5, 10, 20];

    public const DEFAULT_TARGET_PCT = 0.035;

    /** Fill at the next session's open. */
    public const ENTRY_NEXT_OPEN = 'next_open';

    /** Fill only if the next session trades through a level derived from T. */
    public const ENTRY_BREAKOUT_TRIGGER = 'breakout_trigger';

    public const ENTRY_MODES = [self::ENTRY_NEXT_OPEN, self::ENTRY_BREAKOUT_TRIGGER];

    /**
     * Sessions of history loaded before the earliest signal, so a breakout
     * trigger has its twenty-session reference available without a query per
     * observation.
     */
    private const TRIGGER_LOOKBACK_SESSIONS = 25;

    public function __construct(private readonly ExecutionPlanner $planner) {}

    /**
     * @param  array<int, int>  $horizons
     * @return array<string, mixed>
     */
    public function backtest(
        Carbon $from,
        Carbon $to,
        string $version = 'v1',
        array $horizons = self::DEFAULT_HORIZONS,
        float $targetPct = self::DEFAULT_TARGET_PCT,
        ?int $limitPerDay = null,
        string $entryMode = self::ENTRY_NEXT_OPEN,
        ?float $minRiskReward = null,
        ?float $maxEntryGapPct = null,
    ): array {
        $entryMode = in_array($entryMode, self::ENTRY_MODES, true) ? $entryMode : self::ENTRY_NEXT_OPEN;
        $minRiskReward ??= (float) config('execution.min_rr', 2.0);
        $maxEntryGapPct ??= config('execution.max_entry_gap_pct') === null
            ? null
            : (float) config('execution.max_entry_gap_pct');

        $tradingDays = $this->loadTradingDays();
        if ($tradingDays === []) {
            return $this->emptyReport($from, $to, $version, $horizons, $targetPct, $entryMode, $minRiskReward);
        }

        $tradingDayIndex = array_flip($tradingDays);

        $scoreRows = $this->loadScores($from, $to, $version, $limitPerDay);
        if ($scoreRows->isEmpty()) {
            return $this->emptyReport($from, $to, $version, $horizons, $targetPct, $entryMode, $minRiskReward);
        }

        // Pull every relevant price bar in one pass to avoid N+1.
        [$earliestNeeded, $latestNeeded] = $this->priceWindow($scoreRows, $tradingDays, $tradingDayIndex, $horizons);
        $bars = $this->loadBars($scoreRows->pluck('asset_id')->unique()->values()->all(), $earliestNeeded, $latestNeeded);

        $observations = [];
        $flow = [
            'signals' => 0,
            'no_next_session' => 0,
            'not_triggered' => 0,
            'rejected_risk_reward' => 0,
            'no_risk_levels' => 0,
            'missing_data' => 0,
            'triggered' => 0,
        ];

        foreach ($scoreRows as $row) {
            $flow['signals']++;

            $assetId = (int) $row->asset_id;
            $signalDate = $this->normalizeDate($row->scan_date);

            if ($signalDate === null || ! isset($tradingDayIndex[$signalDate])) {
                $flow['missing_data']++;

                continue;
            }

            $signalIndex = $tradingDayIndex[$signalDate];

            // T+1. The whole point: a signal that exists only once T has closed
            // cannot be filled at T.
            $entryDate = $tradingDays[$signalIndex + 1] ?? null;

            if ($entryDate === null) {
                $flow['no_next_session']++;

                continue;
            }

            $signalBar = $bars[$assetId][$signalDate] ?? null;
            $entryBar = $bars[$assetId][$entryDate] ?? null;

            if ($signalBar === null || $entryBar === null || ($entryBar->open ?? null) === null) {
                $flow['missing_data']++;

                continue;
            }

            $fill = $this->resolveFill($entryMode, $assetId, $bars, $signalDate, $signalBar, $entryBar);

            if ($fill === null) {
                $flow['not_triggered']++;

                continue;
            }

            $stop = $row->invalidation_level === null ? null : (float) $row->invalidation_level;
            $target = $row->take_profit === null ? null : (float) $row->take_profit;

            // The risk levels were fixed at T. A gap moves the entry and not
            // the stop, so the trade has to be re-measured at the price paid.
            $verdict = $this->planner->evaluateFill(
                [
                    'entry_trigger' => $fill['trigger'],
                    'stop' => $stop,
                    'target' => $target,
                ],
                $fill['price'],
                $minRiskReward,
                $maxEntryGapPct,
            );

            // A score with no stored levels cannot fail a risk/reward test --
            // there is nothing to test. Rejecting it would silently return an
            // empty report for any history written before the levels existed,
            // so the observation is kept and counted separately: its forward
            // return is real data, its R/R is simply unknown.
            $hasLevels = $stop !== null && $target !== null;

            if ($hasLevels && ! $verdict['passes']) {
                $flow['rejected_risk_reward']++;

                continue;
            }

            if (! $hasLevels) {
                $flow['no_risk_levels']++;
            }

            $flow['triggered']++;

            $perHorizon = $this->forwardReturns(
                $bars[$assetId] ?? [],
                $tradingDays,
                $tradingDayIndex[$entryDate] ?? ($signalIndex + 1),
                $fill['price'],
                $horizons,
                $targetPct,
            );

            if ($perHorizon === []) {
                $flow['missing_data']++;
                $flow['triggered']--;

                continue;
            }

            $observations[] = [
                'symbol' => $row->symbol,
                'scan_date' => $signalDate,
                'entry_date' => $entryDate,
                'entry_price' => $fill['price'],
                'gap_pct' => $verdict['gap_pct'],
                'risk_reward_at_fill' => $hasLevels ? $verdict['risk_reward'] : null,
                'score_total' => (float) $row->score_total,
                'lf_pass' => (bool) $row->lf_pass,
                'rrf_pass' => (bool) $row->rrf_pass,
                'per_horizon' => $perHorizon,
            ];
        }

        $baseline = $this->aggregate($observations, $horizons, static fn () => true);

        $buckets = [];
        foreach ($this->bucketDefinitions() as [$label, $low, $high]) {
            $predicate = static fn (array $o) => $o['score_total'] >= $low && $o['score_total'] < $high;
            $buckets[] = [
                'bucket' => $label,
                'n' => $this->sampleSize($observations, $predicate),
                'by_horizon' => $this->aggregate($observations, $horizons, $predicate, $baseline),
            ];
        }

        $filterCombos = [];
        foreach ($this->filterCombos() as [$label, $predicate]) {
            $filterCombos[] = [
                'combo' => $label,
                'n' => $this->sampleSize($observations, $predicate),
                'by_horizon' => $this->aggregate($observations, $horizons, $predicate, $baseline),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'version' => $version,
            'entry_mode' => $entryMode,
            'min_risk_reward' => $minRiskReward,
            'max_entry_gap_pct' => $maxEntryGapPct,
            'target_pct' => $targetPct,
            'horizons' => $horizons,
            'sample_size' => count($observations),
            'flow' => $flow,
            'baseline' => $baseline,
            'buckets' => $buckets,
            'filter_combos' => $filterCombos,
        ];
    }

    /**
     * Where the trade actually fills on T+1, or null when it never does.
     *
     * @param  array<int, array<string, object>>  $bars
     * @return array{price: float, trigger: ?float}|null
     */
    private function resolveFill(
        string $entryMode,
        int $assetId,
        array $bars,
        string $signalDate,
        object $signalBar,
        object $entryBar,
    ): ?array {
        $open = (float) $entryBar->open;

        if ($entryMode === self::ENTRY_NEXT_OPEN) {
            return $open > 0 ? ['price' => $open, 'trigger' => null] : null;
        }

        $trigger = $this->breakoutTrigger($bars[$assetId] ?? [], $signalDate, $signalBar);

        if ($trigger === null) {
            return null;
        }

        $high = $entryBar->high === null ? null : (float) $entryBar->high;

        // Never touched: no trade. Counting it as one at the close would be
        // the same class of fiction as filling at T.
        if ($high === null || $high < $trigger) {
            return null;
        }

        // Gapped through it: the first price available is the open, and
        // pretending otherwise buys below every print of the session.
        return ['price' => max($open, $trigger), 'trigger' => $trigger];
    }

    /**
     * One tick above the higher of the signal session's high and the twenty
     * sessions before it -- the same level ExecutionPlanner publishes, so the
     * backtest measures the plan the workspace actually shows.
     *
     * @param  array<string, object>  $assetBars
     */
    private function breakoutTrigger(array $assetBars, string $signalDate, object $signalBar): ?float
    {
        if ($signalBar->high === null) {
            return null;
        }

        $dates = array_keys($assetBars);
        sort($dates);
        $position = array_search($signalDate, $dates, true);

        $reference = (float) $signalBar->high;

        if ($position !== false && $position > 0) {
            $priorDates = array_slice($dates, max(0, $position - 20), min(20, $position));

            foreach ($priorDates as $date) {
                $high = $assetBars[$date]->high ?? null;

                if ($high !== null) {
                    $reference = max($reference, (float) $high);
                }
            }
        }

        if ($reference <= 0) {
            return null;
        }

        return IdxTicks::round($reference + IdxTicks::tickFor($reference), $reference);
    }

    /**
     * Forward returns from the fill, measured in trading sessions.
     *
     * MFE and MAE come from the same bars and cost nothing extra: the best and
     * worst the trade was ever worth over the horizon, which is what separates
     * a flat winner from one that spent a week underwater.
     *
     * @param  array<string, object>  $assetBars
     * @param  array<int, string>  $tradingDays
     * @param  array<int, int>  $horizons
     * @return array<int, array{return: float, hit: bool, mfe: float, mae: float}>
     */
    private function forwardReturns(
        array $assetBars,
        array $tradingDays,
        int $entryIndex,
        float $entryPrice,
        array $horizons,
        float $targetPct,
    ): array {
        if ($entryPrice <= 0) {
            return [];
        }

        $perHorizon = [];

        foreach ($horizons as $h) {
            $exitDate = $tradingDays[$entryIndex + $h] ?? null;

            if ($exitDate === null) {
                continue;
            }

            $exitClose = $assetBars[$exitDate]->close ?? null;

            if ($exitClose === null || $exitClose <= 0) {
                continue;
            }

            $best = $entryPrice;
            $worst = $entryPrice;

            for ($step = 0; $step <= $h; $step++) {
                $date = $tradingDays[$entryIndex + $step] ?? null;
                $bar = $date === null ? null : ($assetBars[$date] ?? null);

                if ($bar === null) {
                    continue;
                }

                if ($bar->high !== null) {
                    $best = max($best, (float) $bar->high);
                }

                if ($bar->low !== null) {
                    $worst = min($worst, (float) $bar->low);
                }
            }

            $ret = ($exitClose - $entryPrice) / $entryPrice;

            $perHorizon[$h] = [
                'return' => $ret,
                'hit' => $ret >= $targetPct,
                'mfe' => ($best - $entryPrice) / $entryPrice,
                'mae' => ($worst - $entryPrice) / $entryPrice,
            ];
        }

        return $perHorizon;
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
     * @return Collection<int, WatchlistScore>
     */
    private function loadScores(Carbon $from, Carbon $to, string $version, ?int $limitPerDay)
    {
        $query = WatchlistScore::query()
            ->where('version', $version)
            ->whereDate('scan_date', '>=', $from->toDateString())
            ->whereDate('scan_date', '<=', $to->toDateString())
            ->whereNotNull('asset_id');

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
     * @param  Collection<int, WatchlistScore>  $rows
     * @param  array<int, string>  $tradingDays
     * @param  array<string, int>  $tradingDayIndex
     * @param  array<int, int>  $horizons
     * @return array{0: string, 1: string}
     */
    private function priceWindow($rows, array $tradingDays, array $tradingDayIndex, array $horizons): array
    {
        $maxHorizon = max($horizons);
        $minDate = null;
        $maxDate = null;

        foreach ($rows as $row) {
            $scanDate = $this->normalizeDate($row->scan_date);
            if ($scanDate === null || ! isset($tradingDayIndex[$scanDate])) {
                continue;
            }

            // Back far enough for a breakout trigger's twenty-session
            // reference, so it never costs a query per observation.
            $startIndex = max(0, $tradingDayIndex[$scanDate] - self::TRIGGER_LOOKBACK_SESSIONS);
            $startDate = $tradingDays[$startIndex] ?? $scanDate;

            if ($minDate === null || $startDate < $minDate) {
                $minDate = $startDate;
            }

            // Forward one extra session: entry is T+1, so the horizon is
            // counted from there rather than from the signal.
            $exitIndex = $tradingDayIndex[$scanDate] + $maxHorizon + 1;
            $exitDate = $tradingDays[$exitIndex] ?? end($tradingDays);

            if ($maxDate === null || $exitDate > $maxDate) {
                $maxDate = $exitDate;
            }
        }

        return [(string) ($minDate ?? '1970-01-01'), (string) ($maxDate ?? '1970-01-01')];
    }

    /**
     * @param  array<int, int>  $assetIds
     * @return array<int, array<string, object>>
     */
    private function loadBars(array $assetIds, string $minDate, string $maxDate): array
    {
        if ($assetIds === []) {
            return [];
        }

        // open/high/low as well as close: a T+1 fill is decided by the open
        // and the high, and MFE/MAE need the extremes.
        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $assetIds)
            ->whereDate('date', '>=', $minDate)
            ->whereDate('date', '<=', $maxDate)
            ->select('asset_id', 'date', 'open', 'high', 'low', 'close')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate($row->date);
            if ($date === null) {
                continue;
            }
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $row->{$field} = $row->{$field} === null ? null : (float) $row->{$field};
            }
            $out[(int) $row->asset_id][$date] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @param  array<int, int>  $horizons
     * @param  callable(array<string, mixed>): bool  $predicate
     * @param  array<int, array{horizon: int, hit_rate: float, avg_return: float, n: int}>|null  $baseline
     * @return array<int, array{horizon: int, hit_rate: float, avg_return: float, lift_vs_baseline: float|null, n: int}>
     */
    private function aggregate(array $observations, array $horizons, callable $predicate, ?array $baseline = null): array
    {
        $out = [];
        foreach ($horizons as $h) {
            $hits = 0;
            $sumReturn = 0.0;
            $sumMfe = 0.0;
            $sumMae = 0.0;
            $count = 0;
            foreach ($observations as $obs) {
                if (! $predicate($obs)) {
                    continue;
                }
                if (! isset($obs['per_horizon'][$h])) {
                    continue;
                }
                $count++;
                if ($obs['per_horizon'][$h]['hit']) {
                    $hits++;
                }
                $sumReturn += $obs['per_horizon'][$h]['return'];
                $sumMfe += $obs['per_horizon'][$h]['mfe'] ?? 0.0;
                $sumMae += $obs['per_horizon'][$h]['mae'] ?? 0.0;
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
                'avg_mfe' => $count > 0 ? round($sumMfe / $count, 6) : 0.0,
                'avg_mae' => $count > 0 ? round($sumMae / $count, 6) : 0.0,
                'n' => $count,
                'lift_vs_baseline' => $lift === null ? null : round($lift, 4),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{horizon: int, hit_rate: float, avg_return: float, n: int}>  $baseline
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
            ['LF=fail · RRF=fail', static fn (array $o) => ! $o['lf_pass'] && ! $o['rrf_pass']],
            ['LF=pass · RRF=fail', static fn (array $o) => $o['lf_pass'] && ! $o['rrf_pass']],
            ['LF=fail · RRF=pass', static fn (array $o) => ! $o['lf_pass'] && $o['rrf_pass']],
            ['LF=pass · RRF=pass', static fn (array $o) => $o['lf_pass'] && $o['rrf_pass']],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @param  callable(array<string, mixed>): bool  $predicate
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
     * @param  array<int, int>  $horizons
     */
    private function emptyReport(
        Carbon $from,
        Carbon $to,
        string $version,
        array $horizons,
        float $targetPct,
        string $entryMode = self::ENTRY_NEXT_OPEN,
        float $minRiskReward = 2.0,
    ): array {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'version' => $version,
            'entry_mode' => $entryMode,
            'min_risk_reward' => $minRiskReward,
            'max_entry_gap_pct' => null,
            'target_pct' => $targetPct,
            'horizons' => $horizons,
            'sample_size' => 0,
            'flow' => [
                'signals' => 0,
                'no_next_session' => 0,
                'not_triggered' => 0,
                'rejected_risk_reward' => 0,
                'no_risk_levels' => 0,
                'missing_data' => 0,
                'triggered' => 0,
            ],
            'baseline' => [],
            'buckets' => [],
            'filter_combos' => [],
        ];
    }
}
