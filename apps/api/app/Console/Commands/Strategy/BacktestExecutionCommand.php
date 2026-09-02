<?php

namespace App\Console\Commands\Strategy;

use App\Services\Strategy\ParameterGridComparator;
use App\Services\Strategy\StrategyProfile;
use App\Services\Strategy\StrategyScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Compares the trailing parameters on identical signals, split chronologically.
 *
 * Prints the in-sample, validation and out-of-sample columns side by side on
 * purpose. A cell that leads in-sample and collapses out-of-sample has been
 * fitted to this history, and the only way to see that is to have all three
 * numbers on the same line.
 */
class BacktestExecutionCommand extends Command
{
    protected $signature = 'strategy:backtest-execution
        {--from= : First signal date (YYYY-MM-DD).}
        {--to= : Last signal date (YYYY-MM-DD). Defaults to today.}
        {--lookback=730 : Calendar days back from --to when --from is not given.}
        {--symbol=* : Restrict to one or more tickers.}
        {--score-version=v1 : Which watchlist_scores version supplies the signal spine.}
        {--in-sample=0.6 : Fraction of trades in the in-sample period.}
        {--validation=0.2 : Fraction of trades in the validation period.}
        {--json : Emit the full report as JSON instead of a table.}';

    protected $description = 'Compare trailing-stop parameter combinations over the same historical signals.';

    public function handle(ParameterGridComparator $comparator): int
    {
        $to = $this->resolveDate($this->option('to')) ?? Carbon::now()->startOfDay();
        $from = $this->resolveDate($this->option('from'))
            ?? $to->copy()->subDays(max(1, (int) $this->option('lookback')));

        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return self::INVALID;
        }

        $profile = StrategyProfile::fromConfig();

        $this->info(sprintf('Comparing parameters over %s → %s.', $from->toDateString(), $to->toDateString()));

        $report = $comparator->compare(
            $from,
            $to,
            $profile,
            null,
            $this->parseSymbols((array) $this->option('symbol')),
            (string) ($this->option('score-version') ?: StrategyScoringService::VERSION),
            (float) $this->option('in-sample'),
            (float) $this->option('validation'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d signal(s), %d produced a fill; %d never triggered.',
            $report['signal_flow']['signals'],
            $report['trades_available'],
            $report['signal_flow']['not_triggered'],
        ));

        if ($report['trades_available'] === 0) {
            $this->warn('No fills in this range, so there is nothing to compare.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Splits by trade count: in-sample through %s, validation through %s.',
            (string) ($report['split_boundaries']['in_sample_end'] ?? 'n/a'),
            (string) ($report['split_boundaries']['validation_end'] ?? 'n/a'),
        ));

        $rows = [];

        foreach ($report['cells'] as $cell) {
            $rows[] = [
                sprintf(
                    '%.1f / %.1f / %.1f',
                    $cell['parameters']['trail_activation_gain_pct'],
                    $cell['parameters']['trailing_distance_pct'],
                    $cell['parameters']['minimum_locked_profit_pct'],
                ),
                $cell['all']['trades'],
                $this->pct($cell['all']['hit_rate_5pct']),
                $this->pct($cell['all']['win_rate']),
                $this->num($cell['all']['expectancy_pct']),
                $this->num($cell['all']['median_return_pct']),
                $this->num($cell['all']['max_drawdown_pct']),
                $this->num($cell['all']['profit_factor']),
                $this->num($cell['splits']['in_sample']['expectancy_pct']),
                $this->num($cell['splits']['validation']['expectancy_pct']),
                $this->num($cell['splits']['out_of_sample']['expectancy_pct']),
            ];
        }

        $this->table(
            ['act/trail/floor', 'trades', 'hit+5%', 'win', 'exp%', 'med%', 'maxDD%', 'PF', 'IS exp%', 'VAL exp%', 'OOS exp%'],
            $rows,
        );

        $this->line($report['caveat']);
        $this->line($report['disclaimer']);

        return self::SUCCESS;
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : sprintf('%.1f%%', $value * 100);
    }

    private function num(?float $value): string
    {
        return $value === null ? '—' : sprintf('%.2f', $value);
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<int, string>|null
     */
    private function parseSymbols(array $raw): ?array
    {
        $out = [];

        foreach ($raw as $item) {
            foreach (explode(',', (string) $item) as $symbol) {
                $symbol = strtoupper(trim($symbol));

                if ($symbol !== '' && ! in_array($symbol, $out, true)) {
                    $out[] = $symbol;
                }
            }
        }

        return $out === [] ? null : $out;
    }
}
