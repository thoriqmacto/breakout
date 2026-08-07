<?php

namespace App\Console\Commands\Strategy;

use App\Services\Strategy\WatchlistRanker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RankWatchlistCommand extends Command
{
    protected $signature = 'strategy:rank-watchlist
        {--date= : Scan date (YYYY-MM-DD). Defaults to latest features_daily date.}
        {--top=30 : Maximum number of watchlist rows to keep and persist}
        {--score-version=v1 : Score version label persisted in watchlist_scores.version}
        {--symbol=* : Restrict to one or more tickers (repeatable or comma-separated)}
        {--windows=3,5,10,20 : Window lengths consumed from broker_accumulation_windows}
        {--min-turnover=5000000000 : Minimum daily turnover for liquidity filter}
        {--min-brokers=5 : Minimum unique active brokers for liquidity filter}
        {--min-rr=2.0 : Minimum risk/reward ratio for the RRF filter}';

    protected $description = 'Compute and persist explainable watchlist scores from broker accumulation + breakout features.';

    public function handle(WatchlistRanker $ranker): int
    {
        $date = $this->resolveDate();
        if ($date === null) {
            return self::FAILURE;
        }

        $windows = $this->parseWindows((string) $this->option('windows'));
        $symbols = $this->parseSymbols((array) $this->option('symbol'));

        $result = $ranker->rank($date, [
            'windows' => $windows,
            'symbols' => $symbols,
            'top' => (int) $this->option('top'),
            'version' => (string) $this->option('score-version'),
            'min_turnover' => (float) $this->option('min-turnover'),
            'min_brokers' => (int) $this->option('min-brokers'),
            'min_rr' => (float) $this->option('min-rr'),
        ]);

        if ($result['rows'] === []) {
            $this->warn(sprintf('No watchlist candidates evaluated for %s.', $result['scan_date']));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scan date: %s | version: %s | evaluated: %d | persisted: %d',
            $result['scan_date'],
            $result['version'],
            $result['evaluated'],
            $result['persisted']
        ));

        $this->table(
            ['Symbol', 'Score', 'BAS', 'BCS', 'LF', 'RRF', 'Close', 'Stop', 'Target', 'R/R'],
            array_map(static fn (array $row): array => [
                $row['symbol'],
                number_format((float) $row['score_total'], 2),
                number_format((float) $row['score_bas'], 2),
                number_format((float) $row['score_bcs'], 2),
                $row['lf_pass'] ? '✓' : '·',
                $row['rrf_pass'] ? '✓' : '·',
                number_format((float) $row['close'], 2),
                $row['invalidation_level'] === null ? '-' : number_format((float) $row['invalidation_level'], 2),
                $row['take_profit'] === null ? '-' : number_format((float) $row['take_profit'], 2),
                $row['risk_reward'] === null ? '-' : number_format((float) $row['risk_reward'], 2),
            ], $result['rows'])
        );

        $this->newLine();
        $this->line('Note: outputs are research/watchlist support only — no profit guarantee, no execution.');

        return self::SUCCESS;
    }

    private function resolveDate(): ?Carbon
    {
        $option = (string) ($this->option('date') ?? '');
        if ($option !== '') {
            try {
                return Carbon::parse($option)->startOfDay();
            } catch (\Throwable) {
                $this->error('Invalid --date value. Use YYYY-MM-DD.');

                return null;
            }
        }

        $latest = DB::table('features_daily')->max('date');
        if (! $latest) {
            $this->warn('No rows found in features_daily. Run php artisan features:extract first.');

            return null;
        }

        return Carbon::parse((string) $latest)->startOfDay();
    }

    /**
     * @return array<int, int>
     */
    private function parseWindows(string $raw): array
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

        return $out === [] ? WatchlistRanker::DEFAULT_WINDOWS : array_values($out);
    }

    /**
     * @param  array<int, string>  $rawSymbols
     * @return array<int, string>|null
     */
    private function parseSymbols(array $rawSymbols): ?array
    {
        $symbols = [];
        foreach ($rawSymbols as $s) {
            foreach (explode(',', $s) as $candidate) {
                $sym = strtoupper(trim((string) $candidate));
                if ($sym !== '') {
                    $symbols[$sym] = $sym;
                }
            }
        }

        return $symbols === [] ? null : array_values($symbols);
    }
}
