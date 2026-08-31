<?php

namespace App\Console\Commands;

use App\Models\TradingDay;
use App\Services\TradingDayWriter;
use App\Services\YahooTradingDays;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Import IHSG sessions and closing values from Yahoo.
 *
 * The reporting here is the point as much as the import is. This command used
 * to print "Upserted N trading day records (N with a close value)" where the
 * second figure counted rows already in the table -- so a run that persisted
 * nothing new still reported a healthy-looking count, and a session the
 * provider had supplied a close for but the database had not stored was
 * indistinguishable from a clean success. That is how a NULL close survived
 * repeated repair attempts unnoticed.
 *
 * So the provider's answer and the database's answer are gathered separately
 * and compared. If Yahoo supplied a number for a date and the table still
 * holds NULL for it, that is a failed import and the command says so.
 */
class TradingDaysBuild extends Command
{
    protected $signature = 'trading-days:build
        {--from=2015-01-01}
        {--to=}
        {--no-seeder-sync : Import without rewriting database/seeders/data/trading_days.php}';

    protected $description = 'Populate the trading_days table using Yahoo Finance historical data, repairing unknown closes.';

    public function __construct(
        private readonly YahooTradingDays $service,
        private readonly TradingDayWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = $this->option('to');

        $report = $this->service->import($from, $to ?: null);

        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to ?: Carbon::now()->toDateString())->toDateString();

        // Read the table back rather than trusting the write. The production
        // incident this reporting exists for looked like a successful upsert.
        $stored = TradingDay::query()
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderBy('date')
            ->get(['date', 'close']);

        $storedWithClose = $stored->filter(static fn (TradingDay $day): bool => $day->close !== null)->count();
        $nullDates = $this->writer->incompleteDates($fromDate, $toDate);

        $this->line(sprintf('Yahoo returned (fetched from %s):', $report->fetchedFrom));
        $this->line(sprintf('  %d trading sessions', $report->providerSessions()));
        $this->line(sprintf('  %d with close values', $report->providerClosesCount()));

        $this->line('Database after import:');
        $this->line(sprintf('  %d trading sessions', $stored->count()));
        $this->line(sprintf('  %d with close values', $storedWithClose));
        $this->line(sprintf('  %d null closes', count($nullDates)));

        if ($report->repaired !== []) {
            $this->info(sprintf(
                'Repaired null closes: %s',
                $this->summariseDates($report->repaired),
            ));
        }

        if ($report->preserved !== []) {
            $this->line(sprintf(
                'Kept existing closes the provider could not confirm: %s',
                $this->summariseDates($report->preserved),
            ));
        }

        // The one check that would have caught the incident: the provider gave
        // us a number and the table does not have it.
        $unpersisted = array_values(array_intersect($report->datesWithProviderClose(), $nullDates));

        if ($unpersisted !== []) {
            $this->error(sprintf(
                'Yahoo supplied a close for %d date(s) that the database still holds as NULL: %s',
                count($unpersisted),
                $this->summariseDates($unpersisted),
            ));
            $this->error('The import did not persist what the provider returned.');

            return self::FAILURE;
        }

        if ($nullDates !== []) {
            $this->warn(sprintf(
                'Database still contains NULL IHSG closes: %s',
                $this->summariseDates($nullDates),
            ));
            $this->warn('Yahoo did not supply a close for these sessions; they remain incomplete.');
        }

        try {
            $this->syncSeederFile();
        } catch (\Throwable $e) {
            $this->warn('Unable to update trading day seeder: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function summariseDates(array $dates): string
    {
        $cap = max(1, (int) config('trading_days.max_reported_dates', 20));

        if (count($dates) <= $cap) {
            return implode(', ', $dates);
        }

        return implode(', ', array_slice($dates, 0, $cap)).sprintf(' … and %d more', count($dates) - $cap);
    }

    private function syncSeederFile(): void
    {
        if ($this->option('no-seeder-sync')) {
            $this->line('Seeder file left untouched (--no-seeder-sync).');

            return;
        }

        $records = TradingDay::query()
            ->orderBy('date')
            ->get(['date', 'close'])
            ->map(function (TradingDay $day) {
                $date = $day->getAttribute('date');

                if ($date instanceof Carbon) {
                    $date = $date->toDateString();
                } else {
                    $date = Carbon::parse((string) $date)->toDateString();
                }

                return [
                    'date' => $date,
                    'close' => $day->getAttribute('close'),
                ];
            })
            ->values()
            ->all();

        if (count($records) === 0) {
            $this->warn('No trading day records available to write to the seeder file.');

            return;
        }

        $path = database_path('seeders/data/trading_days.php');
        $existing = $this->readSeederData($path);

        if ($existing !== null && $this->recordsAreEqual($existing, $records)) {
            $this->info('Trading day seeder data is already up to date.');

            return;
        }

        File::ensureDirectoryExists(dirname($path));

        $contents = "<?php\n\nreturn ".$this->exportArray($records).";\n";

        if (File::put($path, $contents) === false) {
            throw new \RuntimeException('Failed to write trading day seeder data.');
        }

        $this->info('Trading day seeder data saved to '.$path.'.');
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readSeederData(string $path): ?array
    {
        if (! File::exists($path)) {
            return null;
        }

        $data = include $path;

        if (! is_array($data)) {
            return null;
        }

        return Collection::make($data)
            ->map(function ($item) {
                if (! is_array($item) || ! isset($item['date'])) {
                    return null;
                }

                try {
                    $date = Carbon::parse((string) $item['date'])->toDateString();
                } catch (\Throwable) {
                    return null;
                }

                return [
                    'date' => $date,
                    'close' => $item['close'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $first
     * @param  array<int, array<string, mixed>>  $second
     */
    private function recordsAreEqual(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        return $first == $second; // phpcs:ignore
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function exportArray(array $records): string
    {
        $export = var_export($records, true);

        $export = preg_replace('/^([ ]*)array \(/m', '$1[', $export);
        $export = preg_replace('/\)(,?)$/m', ']$1', $export);

        return str_replace('NULL', 'null', (string) $export);
    }
}
