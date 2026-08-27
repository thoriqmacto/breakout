<?php

namespace App\Console\Commands\Strategy;

use App\Services\Strategy\BrokerAccumulationAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RollupBrokerAccumulationCommand extends Command
{
    protected $signature = 'strategy:rollup-broker-accumulation
        {--date= : Window end date (YYYY-MM-DD). Defaults to the latest imported broker summary end date.}
        {--windows=3,5,10,20 : Comma-separated window lengths in days}
        {--symbol=* : Restrict to one or more tickers (repeatable or comma-separated)}';

    protected $description = 'Aggregate imported broker summary windows into accumulation windows ending on a date.';

    public function handle(BrokerAccumulationAggregator $aggregator): int
    {
        $date = $this->resolveEndDate();
        if ($date === null) {
            return self::FAILURE;
        }

        $windows = $this->parseWindows((string) $this->option('windows'));
        $symbols = $this->parseSymbols((array) $this->option('symbol'));

        $this->info(sprintf(
            'Rolling up broker accumulation: end=%s, windows=[%s]%s',
            $date->toDateString(),
            implode(',', $windows),
            $symbols === null ? '' : sprintf(', symbols=[%s]', implode(',', $symbols))
        ));

        $result = $aggregator->rollup($date, $windows, $symbols);

        $this->info(sprintf(
            'Rolled up %d windows across %d assets (%d at their own length).',
            $result['rows_written'],
            $result['assets'],
            $result['native_rows']
        ));

        return self::SUCCESS;
    }

    private function resolveEndDate(): ?Carbon
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

        // The window table is the source now, and it is a superset: the
        // importer writes a window for every file and broker_summary_facts only
        // for the genuinely single-day ones. Defaulting off facts would pick a
        // stale date whenever the recent imports were ranges.
        $latest = DB::table('broker_summary_windows')->max('to_date');

        if (! $latest) {
            $this->warn('No imported broker summary windows found. Provide --date explicitly.');

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

        return $out === [] ? BrokerAccumulationAggregator::DEFAULT_WINDOWS : array_values($out);
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
