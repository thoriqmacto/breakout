<?php

namespace App\Services;

use App\Support\PathOwnership;
use DateTime;
use RuntimeException;

class CsvBars
{
    /**
     * Read a CSV file and return rows keyed by YYYY-MM-DD.
     *
     * @param  string  $path  Path to the CSV file.
     * @return array Rows indexed by date.
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        if (($h = fopen($path, 'r')) === false) {
            return [];
        }
        $header = null;
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                $header = array_map(fn ($x) => strtolower(trim($x)), $r);

                continue;
            }
            $rec = array_combine($header, $r);
            if (! $rec) {
                continue;
            }
            $date = self::dateFrom($rec);
            if (! $date) {
                continue;
            }

            $rows[$date] = [
                'date' => $date,
                'open' => self::field($rec, 'open'),
                'high' => self::field($rec, 'high'),
                'low' => self::field($rec, 'low'),
                'close' => self::field($rec, 'close'),
                'volume' => (int) self::field($rec, 'volume'),
            ];
        }
        fclose($h);
        ksort($rows);

        return $rows;
    }

    /**
     * Write rows keyed by date back to CSV with a stable header and sorting.
     *
     * @param  string  $path  Destination CSV path.
     * @param  array  $rows  Rows keyed by date.
     */
    public static function write(string $path, array $rows): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException(self::writeFailure($dir));
        }

        $tmp = $path.'.tmp';
        $h = @fopen($tmp, 'w');

        // Unchecked, this handed `false` to fputcsv() and the run died on a
        // type error three lines later -- or, before that, on PHP's own
        // "Failed to open stream: Permission denied", which names the file and
        // neither the user that could not write it nor the user that owns the
        // directory. Those two names are the whole diagnosis.
        if ($h === false) {
            throw new RuntimeException(self::writeFailure($dir));
        }

        fputcsv($h, ['Date', 'Open', 'High', 'Low', 'Close', 'Volume'], ',', '"', '\\');
        ksort($rows);
        foreach ($rows as $d => $r) {
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            $outDate = $dt ? $dt->format('d/m/Y') : $d;
            fputcsv($h, [$outDate, $r['open'], $r['high'], $r['low'], $r['close'], $r['volume']], ',', '"', '\\');
        }
        fclose($h);

        // A failed rename used to be silent, which is the worst of the three
        // outcomes: the caller counts a bar as saved, the destination still
        // holds yesterday's file, and a scratch file is left behind to be
        // mistaken for data later.
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException(self::writeFailure($dir));
        }
    }

    /**
     * Name the directory, its owner, and the user that could not write there.
     *
     * This has now cost three separate investigations in one week -- the
     * storage logs, the browser profile, and this -- each of which began with
     * a message that said permission was denied without saying to whom. The
     * cause is always the same shape: a directory established by one user and
     * written by another after the scheduler moved.
     */
    private static function writeFailure(string $directory): string
    {
        $reason = PathOwnership::writeReason($directory);
        $current = PathOwnership::currentUser();
        $owner = PathOwnership::owner($directory);

        if ($owner === null || $owner === $current) {
            return sprintf(
                'Could not write to %s: the directory %s for %s. Check its ownership and mode.',
                $directory,
                $reason,
                $current,
            );
        }

        return sprintf(
            'Could not write to %s: the directory %s for %s, and it belongs to %s. '
            .'Either run as %s, or give %s ownership of the data directory '
            .'(chown -R %s %s). A data directory differs from a browser profile here: '
            .'sharing it is the intended arrangement.',
            $directory,
            $reason,
            $current,
            $owner,
            $owner,
            $current,
            $current,
            $directory,
        );
    }

    /**
     * Merge arrays keyed by date; right side wins on conflicts.
     *
     * @param  array  $base  Base dataset keyed by date.
     * @param  array  $add  Additional data keyed by date.
     * @return array Merged result.
     */
    public static function merge(array $base, array $add): array
    {
        foreach ($add as $d => $row) {
            $base[$d] = $row;
        }
        ksort($base);

        return $base;
    }

    /**
     * Extract a numeric field from a CSV record, supporting headers with suffixes
     * like "Open_TICKER" or "Adj Close_TICKER". Comparison is case-insensitive
     * and ignores spaces before the first underscore.
     */
    private static function field(array $rec, string $name): float
    {
        foreach ($rec as $k => $v) {
            $base = strtolower(trim(explode('_', $k, 2)[0]));
            $base = str_replace(' ', '', $base);
            if ($base === $name) {
                return is_numeric($v) ? (float) $v : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Extract a YYYY-MM-DD date string from a CSV record.
     *
     * @param  array  $rec  CSV record.
     * @return string|null Date string if found, otherwise null.
     */
    private static function dateFrom(array $rec): ?string
    {
        // Support common header variants
        foreach (['date', 'timestamp', 'day'] as $k) {
            if (! empty($rec[$k])) {
                $raw = substr((string) $rec[$k], 0, 10);
                $dt = DateTime::createFromFormat('Y-m-d', $raw)
                    ?: DateTime::createFromFormat('d/m/Y', $raw)
                    ?: DateTime::createFromFormat('d-m-Y', $raw)
                    ?: DateTime::createFromFormat('m-d-Y', $raw);
                if ($dt) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        return null;
    }
}
