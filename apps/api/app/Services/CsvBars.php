<?php
namespace App\Services;

class CsvBars
{
    /** Read CSV to rows keyed by YYYY-MM-DD (dedupe-friendly) */
    public static function read(string $path): array {
        if (!is_file($path)) return [];
        if (($h = fopen($path, 'r')) === false) return [];
        $header = null; $rows = [];
        while (($r = fgetcsv($h)) !== false) {
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

    /** Write rows (assoc keyed by date) back to CSV (stable header + sorted) */
    public static function write(string $path, array $rows): void {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $tmp = $path.'.tmp';
        $h = fopen($tmp, 'w');
        fputcsv($h, ['Date','Open','High','Low','Close','Volume']);
        ksort($rows);
        foreach ($rows as $d => $r) {
            fputcsv($h, [$d, $r['open'], $r['high'], $r['low'], $r['close'], $r['volume']]);
        }
        fclose($h);
        rename($tmp, $path);
    }

    /** Merge arrays keyed by date; right side wins on conflicts */
    public static function merge(array $base, array $add): array {
        foreach ($add as $d => $row) { $base[$d] = $row; }
        ksort($base);
        return $base;
    }

    private static function dateFrom(array $rec): ?string {
        // Support common header variants
        foreach (['date','timestamp','day'] as $k) {
            if (!empty($rec[$k])) return substr((string)$rec[$k], 0, 10);
        }
        return null;
    }
}
