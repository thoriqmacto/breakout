<?php

namespace App\Services;

use Illuminate\Support\Str;

class CsvUtilities
{
    /**
     * Stream CSV rows to avoid loading the entire file into memory.
     */
    public static function streamCsv(string $path): \Generator
    {
        $h = fopen($path, 'r');
        if (!$h) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        $headers = null;
        $line = 0;

        while (($data = fgetcsv($h)) !== false) {
            if ($line === 0) {
                if (isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]); // strip BOM
                }
                $headers = array_map(fn($x) => Str::of($x)->lower()->trim()->value(), $data);
            } else {
                $row = [];
                foreach ($headers as $i => $key) {
                    $row[$key] = $data[$i] ?? null;
                }
                if (!empty($row['date'])) {
                    yield $row;     // yield one row at a time
                }
            }
            $line++;
        }
        fclose($h);
    }

    /**
     * Legacy method kept for reference; parses entire CSV into memory.
     */
    public static function parseCsv(string $path): array
    {
        $h = fopen($path, 'r');
        if (!$h) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        $headers = [];
        $rows = [];
        $line = 0;

        while (($data = fgetcsv($h)) !== false) {
            if ($line === 0 && isset($data[0])) {
                // strip BOM if present
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                $headers = array_map(fn($x) => Str::of($x)->lower()->trim()->value(), $data);
            } else {
                $row = [];
                foreach ($headers as $i => $key) {
                    $row[$key] = $data[$i] ?? null;
                }
                // only keep rows with a non-empty date-like field
                if (!empty($row['date'])) {
                    $rows[] = $row;
                }
            }
            $line++;
        }
        fclose($h);

        return [$headers, $rows];
    }

    /**
     * Return the first non-empty value from the provided keys.
     */
    public static function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return (string) $row[$k];
            }
        }
        return null;
    }

    /**
     * Normalize various date formats to Y-m-d.
     */
    public static function normDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);

        // 19/08/2010 -> 2010-08-19 (assume D/M/Y for slashes)
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $s)) {
            $dt = \DateTime::createFromFormat('d/m/Y', $s);
            return $dt ? $dt->format('Y-m-d') : null;
        }
        // 19-08-2010 or 08-19-2010
        if (preg_match('#^\d{1,2}-\d{1,2}-\d{4}$#', $s)) {
            $dt = \DateTime::createFromFormat('d-m-Y', $s)
                ?: \DateTime::createFromFormat('m-d-Y', $s);
            return $dt ? $dt->format('Y-m-d') : null;
        }
        // Already ISO
        if (preg_match('#^\d{4}-\d{1,2}-\d{1,2}$#', $s)) {
            return $s;
        }
        // 20100819, 19 Aug 2010, etc.
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    /**
     * Parse a numeric CSV column into a float.
     */
    public static function num(array $row, string $key): ?float
    {
        foreach ([$key, ucfirst($key), strtoupper($key)] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== '' && $row[$k] !== null) {
                $val = str_replace([',',' '], '', (string) $row[$k]);
                return is_numeric($val) ? (float) $val : null;
            }
        }
        return null;
    }

    /**
     * Parse a numeric CSV column into an integer volume.
     */
    public static function vol(array $row, string $key): ?int
    {
        foreach ([$key, ucfirst($key), strtoupper($key)] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== '' && $row[$k] !== null) {
                $val = preg_replace('/[^\d]/', '', (string) $row[$k]);
                return $val === '' ? null : (int) $val;
            }
        }
        return null;
    }
}
