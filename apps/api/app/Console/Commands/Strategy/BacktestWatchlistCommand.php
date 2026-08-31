<?php

namespace App\Console\Commands\Strategy;

use App\Services\Strategy\WatchlistBacktester;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BacktestWatchlistCommand extends Command
{
    protected $signature = 'strategy:backtest-watchlist
        {--from= : Start date (YYYY-MM-DD). Defaults to 90 days before --to.}
        {--to= : End date (YYYY-MM-DD). Defaults to today.}
        {--score-version=v1 : Watchlist score version label}
        {--horizons=5,10,20 : Forward-return horizons in trading days}
        {--target-pct=0.035 : Hit threshold (e.g. 0.035 for +3.5%)}
        {--limit-per-day= : Optional cap on rows per scan day (top score first)}
        {--entry=next_open : Fill model: next_open or breakout_trigger}
        {--min-rr= : Reject a fill whose risk/reward at the price paid is below this (default: execution.min_rr)}
        {--max-gap-pct= : Reject a fill that opens this far beyond the trigger, as a fraction}
        {--json : Emit a single JSON document instead of human-readable tables}';

    protected $description = 'Replay persisted watchlist_scores against forward returns, entering on the session after the signal. Reads only; no writes.';

    public function handle(WatchlistBacktester $backtester): int
    {
        $to = $this->parseDate($this->option('to'), Carbon::today());
        if ($to === null) {
            $this->error('Invalid --to value.');

            return self::FAILURE;
        }
        $from = $this->parseDate($this->option('from'), $to->copy()->subDays(90));
        if ($from === null) {
            $this->error('Invalid --from value.');

            return self::FAILURE;
        }
        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $horizons = $this->parseIntList((string) $this->option('horizons'), WatchlistBacktester::DEFAULT_HORIZONS);
        $targetPct = (float) $this->option('target-pct');
        $version = (string) $this->option('score-version');
        $limitPerDay = $this->option('limit-per-day');
        $limitPerDay = $limitPerDay === null || $limitPerDay === '' ? null : (int) $limitPerDay;

        $entryMode = (string) $this->option('entry');

        if (! in_array($entryMode, WatchlistBacktester::ENTRY_MODES, true)) {
            $this->error('--entry must be one of: '.implode(', ', WatchlistBacktester::ENTRY_MODES));

            return self::FAILURE;
        }

        $minRr = $this->option('min-rr');
        $minRr = $minRr === null || $minRr === '' ? null : (float) $minRr;

        $maxGap = $this->option('max-gap-pct');
        $maxGap = $maxGap === null || $maxGap === '' ? null : (float) $maxGap;

        $report = $backtester->backtest(
            $from,
            $to,
            $version,
            $horizons,
            $targetPct,
            $limitPerDay,
            $entryMode,
            $minRr,
            $maxGap,
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->printReport($report);
    }

    private function printReport(array $report): int
    {
        $this->info(sprintf(
            'Backtest %s → %s | version=%s | entry=%s (T+1) | min R/R at fill=%.2f | target=%.2f%% | horizons=[%s] | sample=%d',
            $report['from'],
            $report['to'],
            $report['version'],
            $report['entry_mode'],
            $report['min_risk_reward'],
            $report['target_pct'] * 100,
            implode(',', $report['horizons']),
            $report['sample_size']
        ));

        $flow = $report['flow'] ?? [];

        if ($flow !== []) {
            $this->newLine();
            $this->line('<options=bold>Signal flow</>');
            $this->table(
                ['Signals', 'Filled', 'Never triggered', 'Rejected on R/R at fill', 'No stored risk levels', 'No next session', 'Missing data'],
                [[
                    $flow['signals'] ?? 0,
                    $flow['triggered'] ?? 0,
                    $flow['not_triggered'] ?? 0,
                    $flow['rejected_risk_reward'] ?? 0,
                    $flow['no_risk_levels'] ?? 0,
                    $flow['no_next_session'] ?? 0,
                    $flow['missing_data'] ?? 0,
                ]]
            );
        }

        if ($report['sample_size'] === 0) {
            $this->warn('No observations. Have you persisted watchlist_scores for this range and version?');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>Baseline (all observations)</>');
        $this->table(
            ['Horizon', 'Hit rate', 'Avg return', 'Avg MFE', 'Avg MAE', 'N'],
            array_map(static function (array $row): array {
                return [
                    $row['horizon'].'d',
                    number_format(($row['hit_rate'] ?? 0) * 100, 2).'%',
                    number_format(($row['avg_return'] ?? 0) * 100, 2).'%',
                    number_format(($row['avg_mfe'] ?? 0) * 100, 2).'%',
                    number_format(($row['avg_mae'] ?? 0) * 100, 2).'%',
                    (int) ($row['n'] ?? 0),
                ];
            }, $report['baseline'])
        );

        $this->newLine();
        $this->line('<options=bold>By score bucket</>');
        $this->printGroupedRows($report['buckets'], 'bucket');

        $this->newLine();
        $this->line('<options=bold>By filter combo</>');
        $this->printGroupedRows($report['filter_combos'], 'combo');

        $this->newLine();
        $this->line('Note: research only. Entry is the session after the signal -- a score computed from');
        $this->line('session T\'s close did not exist while T was trading. Lift = bucket hit rate − baseline');
        $this->line('hit rate. Small N → noisy lift.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function printGroupedRows(array $groups, string $labelKey): void
    {
        $rows = [];
        foreach ($groups as $group) {
            $byHorizon = $group['by_horizon'] ?? [];
            foreach ($byHorizon as $h) {
                $lift = $h['lift_vs_baseline'];
                $rows[] = [
                    $group[$labelKey] ?? '?',
                    (int) ($group['n'] ?? 0),
                    $h['horizon'].'d',
                    number_format(($h['hit_rate'] ?? 0) * 100, 2).'%',
                    number_format(($h['avg_return'] ?? 0) * 100, 2).'%',
                    $lift === null ? '—' : sprintf('%+0.2f pp', $lift * 100),
                    (int) ($h['n'] ?? 0),
                ];
            }
        }

        $this->table(['Group', 'Group N', 'Horizon', 'Hit rate', 'Avg return', 'Lift', 'N'], $rows);
    }

    private function parseDate(mixed $raw, Carbon $default): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return $default->copy()->startOfDay();
        }
        try {
            return Carbon::parse((string) $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, int>  $fallback
     * @return array<int, int>
     */
    private function parseIntList(string $raw, array $fallback): array
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                continue;
            }
            $n = (int) $part;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        return $out === [] ? $fallback : array_values($out);
    }
}
