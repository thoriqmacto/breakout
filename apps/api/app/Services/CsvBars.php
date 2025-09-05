<?php
namespace App\Services;

use DateTime;

class CsvBars
{
    /**
     * Read a CSV file and return rows keyed by YYYY-MM-DD.
     *
     * @param string $path Path to the CSV file.
     * @return array Rows indexed by date.
     */
    public static function read(string $path): array {
        if (!is_file($path)) return [];
        if (($h = fopen($path, 'r')) === false) return [];
        $header = null; $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if ($header === null) { $header = array_map(fn($x)=>strtolower(trim($x)), $r); continue; }
            $rec = array_combine($header, $r);
            if (!$rec) continue;
            $date = self::dateFrom($rec);
            if (!$date) continue;
            $rows[$date] = [
                'date'   => $date,
                'open'   => (float)($rec['open']??0),
                'high'   => (float)($rec['high']??0),
                'low'    => (float)($rec['low']??0),
                'close'  => (float)($rec['close']??0),
                'volume' => (int)($rec['volume']??0),
            ];
        }
        fclose($h);
        ksort($rows);
        return $rows;
    }

    /**
     * Write rows keyed by date back to CSV with a stable header and sorting.
     *
     * @param string $path Destination CSV path.
     * @param array  $rows Rows keyed by date.
     * @return void
     */
    public static function write(string $path, array $rows): void {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $tmp = $path.'.tmp';
        $h = fopen($tmp, 'w');
        fputcsv($h, ['Date','Open','High','Low','Close','Volume']);
        ksort($rows);
        foreach ($rows as $d => $r) {
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            $outDate = $dt ? $dt->format('d/m/Y') : $d;
            fputcsv($h, [$outDate, $r['open'], $r['high'], $r['low'], $r['close'], $r['volume']]);
        }
        fclose($h);
        rename($tmp, $path);
    }

    /**
     * Merge arrays keyed by date; right side wins on conflicts.
     *
     * @param array $base Base dataset keyed by date.
     * @param array $add  Additional data keyed by date.
     * @return array Merged result.
     */
    public static function merge(array $base, array $add): array {
        foreach ($add as $d => $row) { $base[$d] = $row; }
        ksort($base);
        return $base;
    }

    /**
     * Extract a YYYY-MM-DD date string from a CSV record.
     *
     * @param array $rec CSV record.
     * @return string|null Date string if found, otherwise null.
     */
    private static function dateFrom(array $rec): ?string {
        // Support common header variants
        foreach (['date','timestamp','day'] as $k) {
            if (!empty($rec[$k])) {
                $raw = substr((string)$rec[$k], 0, 10);
                $dt = DateTime::createFromFormat('Y-m-d', $raw)
                    ?: DateTime::createFromFormat('d/m/Y', $raw)
                    ?: DateTime::createFromFormat('d-m-Y', $raw)
                    ?: DateTime::createFromFormat('m-d-Y', $raw);
                if ($dt) return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
