<?php

namespace App\Console\Commands;

use App\Models\TradingDay;
use App\Services\TradingDayLedger;
use App\Services\TradingDayWriter;
use App\Services\YahooTradingDays;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
 *
 * There is a second source, and it is the one that makes this recoverable at
 * all. `database/seeders/data/trading_days.php` is version controlled, so a
 * close it has recorded survives any number of bad provider responses. When
 * Yahoo will not supply a value the table is missing, the ledger is asked
 * before the run is written off as incomplete -- a repair that needs no
 * network and no manual SQL, and that works for any date the file has ever
 * held. The same ledger is written back at the end of the run, by merge
 * rather than by overwrite, so an import that learned nothing about a session
 * cannot erase what the file already knew about it.
 */
class TradingDaysBuild extends Command
{
    protected $signature = 'trading-days:build
        {--from=2015-01-01}
        {--to=}
        {--no-ledger-repair : Do not fill remaining unknown closes from the checked-in trading-day ledger}
        {--no-seeder-sync : Import without rewriting database/seeders/data/trading_days.php}';

    protected $description = 'Populate the trading_days table using Yahoo Finance historical data, repairing unknown closes.';

    public function __construct(
        private readonly YahooTradingDays $service,
        private readonly TradingDayWriter $writer,
        private readonly TradingDayLedger $ledger,
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

        $nullDates = $this->writer->incompleteDates($fromDate, $toDate);
        $ledgerRepaired = $this->repairFromLedger($nullDates);

        if ($ledgerRepaired !== []) {
            // Re-read: the ledger pass has just changed what is incomplete.
            $nullDates = $this->writer->incompleteDates($fromDate, $toDate);
        }

        // Read the table back rather than trusting the write. The production
        // incident this reporting exists for looked like a successful upsert.
        $stored = TradingDay::query()
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderBy('date')
            ->get(['date', 'close']);

        $storedWithClose = $stored->filter(static fn (TradingDay $day): bool => $day->close !== null)->count();

        $this->line(sprintf('Yahoo returned (fetched from %s):', $report->fetchedFrom));
        $this->line(sprintf('  %d trading sessions', $report->providerSessions()));
        $this->line(sprintf('  %d with close values', $report->providerClosesCount()));

        $this->line('Database after import:');
        $this->line(sprintf('  %d trading sessions', $stored->count()));
        $this->line(sprintf('  %d with close values', $storedWithClose));
        $this->line(sprintf('  %d null closes', count($nullDates)));

        if ($report->repaired !== []) {
            $this->info(sprintf(
                'Repaired null closes from Yahoo: %s',
                $this->summariseDates($report->repaired),
            ));
        }

        if ($ledgerRepaired !== []) {
            $this->info(sprintf(
                'Repaired null closes from the checked-in ledger: %s',
                $this->summariseDates($ledgerRepaired),
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
            $this->warn('Neither Yahoo nor the checked-in ledger has a close for these sessions; they remain incomplete.');
        }

        try {
            $this->syncLedgerFile();
        } catch (\Throwable $e) {
            $this->warn('Unable to update trading day seeder: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Fill unknown closes from the version-controlled ledger.
     *
     * This is the same rule the writer enforces, sourced from the file instead
     * of the provider: a close the repository has recorded is known
     * information, and known always beats unknown. It cannot invent a value --
     * only dates the ledger already holds a number for are touched -- so there
     * is nothing here that is specific to any one session.
     *
     * @param  array<int, string>  $nullDates
     * @return array<int, string> the dates actually repaired
     */
    private function repairFromLedger(array $nullDates): array
    {
        if ($nullDates === [] || $this->option('no-ledger-repair')) {
            return [];
        }

        $closes = $this->ledger->knownCloses($nullDates);

        if ($closes === []) {
            return [];
        }

        $result = $this->writer->write(array_map(
            static fn (string $date, float $close): array => ['date' => $date, 'close' => $close],
            array_keys($closes),
            array_values($closes),
        ));

        return $result['repaired'];
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

    /**
     * Fold the table back into the ledger file.
     *
     * A merge, never a dump. The previous version wrote the database out
     * verbatim, so a session the table held as NULL replaced whatever close
     * the file had recorded for it -- destroying the only copy that was not
     * dependent on the provider, and turning a recoverable gap into a
     * permanent one.
     */
    private function syncLedgerFile(): void
    {
        if ($this->option('no-seeder-sync')) {
            $this->line('Seeder file left untouched (--no-seeder-sync).');

            return;
        }

        $records = TradingDay::query()
            ->orderBy('date')
            ->get(['date', 'close'])
            ->map(function (TradingDay $day): array {
                $date = $day->getAttribute('date');

                return [
                    'date' => $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString(),
                    'close' => $day->getAttribute('close'),
                ];
            })
            ->values()
            ->all();

        if ($records === []) {
            $this->warn('No trading day records available to write to the seeder file.');

            return;
        }

        $result = $this->ledger->sync($records);

        if ($result['preserved'] !== []) {
            $this->line(sprintf(
                'Kept %d ledger close(s) the database does not know: %s',
                count($result['preserved']),
                $this->summariseDates($result['preserved']),
            ));
        }

        if (! $result['changed']) {
            $this->info('Trading day seeder data is already up to date.');

            return;
        }

        $this->info(sprintf(
            'Trading day seeder data saved to %s (%d sessions, %d added, %d filled in).',
            $result['path'],
            $result['total'],
            count($result['added']),
            count($result['filled']),
        ));
    }
}
