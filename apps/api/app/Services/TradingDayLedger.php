<?php

namespace App\Services;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The checked-in trading-day file, treated as a ledger rather than a dump.
 *
 * `database/seeders/data/trading_days.php` is the only copy of the IHSG
 * calendar that survives outside the database, and it is version controlled,
 * so it is the one place a close cannot be lost to a bad provider response.
 * That only holds if it obeys the same rule the table does:
 *
 *     a known close is stronger information than an unknown one, and unknown
 *     must never overwrite known.
 *
 * It did not. `trading-days:build` rewrote this file from the database
 * verbatim at the end of every run, NULLs included, so one import where the
 * provider omitted a session's value was enough to erase the number from the
 * ledger too -- and with both copies unknown, nothing was left to repair from.
 * The row could then only heal if Yahoo happened to return that date again.
 *
 * So writes merge instead of replacing: a session the database knows nothing
 * about keeps whatever the file already recorded. That makes the ledger a
 * repair source in its own right, which is the other half of this class --
 * `knownCloses()` is what the build command falls back to when the provider
 * cannot supply a figure the file already has.
 */
class TradingDayLedger
{
    /**
     * Resolved per call, never cached: the database path is redirectable at
     * runtime, and a constructor-time snapshot pins the ledger to whichever
     * path happened to be configured when the container built this service.
     */
    public function path(): string
    {
        return database_path('seeders/data/trading_days.php');
    }

    public function exists(): bool
    {
        return File::exists($this->path());
    }

    /**
     * Every session the ledger records, keyed by date.
     *
     * Present with null means the file recorded the session but not its close;
     * absent means the file has never heard of the date. Callers act on the
     * difference, so the two are not collapsed.
     *
     * @return array<string, float|null>
     */
    public function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $records = include $this->path();

        if (! is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $date = $this->normalizeDate($record['date'] ?? null);

            if ($date === null) {
                continue;
            }

            $close = $this->normalizeClose($record['close'] ?? null);

            // A duplicate date carrying no close cannot erase the one that did.
            if (array_key_exists($date, $out) && $close === null) {
                continue;
            }

            $out[$date] = $close;
        }

        ksort($out);

        return $out;
    }

    /**
     * Closes the ledger actually knows, for the dates asked about.
     *
     * Sessions the file records without a value are omitted: they are no more
     * informative than the NULL the caller is trying to repair.
     *
     * @param  array<int, string>  $dates  empty for every known close
     * @return array<string, float>
     */
    public function knownCloses(array $dates = []): array
    {
        $ledger = array_filter($this->read(), static fn (?float $close): bool => $close !== null);

        if ($dates === []) {
            return $ledger;
        }

        $wanted = [];

        foreach ($dates as $date) {
            $normalized = $this->normalizeDate($date);

            if ($normalized !== null && isset($ledger[$normalized])) {
                $wanted[$normalized] = $ledger[$normalized];
            }
        }

        ksort($wanted);

        return $wanted;
    }

    /**
     * Fold database records into the ledger and write it back.
     *
     * The merge is the guarantee: a record whose close is unknown can add a
     * session to the file, and can never take a close out of it.
     *
     * @param  iterable<int, array{date: mixed, close?: mixed}>  $records
     * @return array{
     *     path: string,
     *     changed: bool,
     *     total: int,
     *     added: array<int, string>,
     *     filled: array<int, string>,
     *     preserved: array<int, string>,
     * }
     */
    public function sync(iterable $records): array
    {
        $ledger = $this->read();

        $added = [];
        $filled = [];
        $preserved = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $date = $this->normalizeDate($record['date'] ?? null);

            if ($date === null) {
                continue;
            }

            $close = $this->normalizeClose($record['close'] ?? null);
            $known = array_key_exists($date, $ledger);

            if (! $known) {
                $added[] = $date;
                $ledger[$date] = $close;

                continue;
            }

            if ($close === null) {
                // The database does not know this close. The file might, and
                // if it does that value is the one worth keeping.
                if ($ledger[$date] !== null) {
                    $preserved[] = $date;
                }

                continue;
            }

            if ($ledger[$date] === null) {
                $filled[] = $date;
            }

            $ledger[$date] = $close;
        }

        ksort($ledger);

        $changed = $this->write($ledger);

        return [
            'path' => $this->path(),
            'changed' => $changed,
            'total' => count($ledger),
            'added' => $added,
            'filled' => $filled,
            'preserved' => $preserved,
        ];
    }

    /**
     * Replace the file contents, or report that they already match.
     *
     * @param  array<string, float|null>  $ledger
     */
    private function write(array $ledger): bool
    {
        if ($ledger === []) {
            return false;
        }

        $records = [];

        foreach ($ledger as $date => $close) {
            $records[] = ['date' => $date, 'close' => $close];
        }

        $contents = "<?php\n\nreturn ".$this->exportArray($records).";\n";

        if ($this->exists() && File::get($this->path()) === $contents) {
            return false;
        }

        File::ensureDirectoryExists(dirname($this->path()));

        if (File::put($this->path(), $contents) === false) {
            throw new RuntimeException('Failed to write trading day ledger data to '.$this->path().'.');
        }

        return true;
    }

    /**
     * @param  array<int, array{date: string, close: float|null}>  $records
     */
    private function exportArray(array $records): string
    {
        $export = var_export($records, true);

        $export = preg_replace('/^([ ]*)array \(/m', '$1[', (string) $export);
        $export = preg_replace('/\)(,?)$/m', ']$1', (string) $export);

        return str_replace('NULL', 'null', (string) $export);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function normalizeClose(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }
}
