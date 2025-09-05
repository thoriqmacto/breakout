<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BulkAssetCsvSeeder extends Seeder
{
    /**
     * Path to directory containing per-asset CSVs.
     */
    protected string $csvDir;

    /**
     * Number of rows to insert per batch.
     */
    protected int $chunk;

    /**
     * Pull configuration values on construction.
     */
    public function __construct()
    {
        $this->csvDir = config('csv.seed_dir');
        $this->chunk = (int) config('csv.chunk_size', 200);
    }

    /**
     * Iterate over CSV files and seed price bars.
     */
    public function run(): void
    {
        // Turn off query log to save memory
        DB::connection()->disableQueryLog();

        // optional: widen time limit to avoid timeouts on large imports
        @set_time_limit(0);

        $dir = $this->csvDir;
        if (!is_dir($dir)) {
            $this->command?->warn("CSV dir not found: {$dir}");
            return;
        }

        $files = collect(File::files($dir))
            ->filter(fn($f) => Str::lower($f->getExtension()) === 'csv')
            ->values();

        if ($files->isEmpty()) {
            $this->command?->warn('No CSV files found in '.$dir);
            return;
        }

        foreach ($files as $file) {
            $symbol = Str::upper(pathinfo($file->getFilename(), PATHINFO_FILENAME));
            $this->seedOneSymbol($symbol, $file->getPathname());
        }
    }

    /**
     * Seed price bars for a single symbol from a CSV file.
     */
    private function seedOneSymbol(string $symbol, string $path): void
    {
        $assetId = $this->getOrCreateAssetId($symbol);

        $rows = $this->streamCsv($path); // generator (no big arrays)

        $batch = [];
        $count = 0;

        foreach ($rows as $r) {
            $rawDate = $this->pick($r, ['date','Date','DATE']);
            $date = $this->normDate($rawDate);
            if (!$date) {
                continue;
            }

            $batch[] = [
                'asset_id' => $assetId,
                'date'     => $date,
                'open'     => $this->num($r, 'open'),
                'high'     => $this->num($r, 'high'),
                'low'      => $this->num($r, 'low'),
                'close'    => $this->num($r, 'close'),
                'volume'   => $this->vol($r, 'volume'),
            ];

            if (count($batch) >= $this->chunk) {
                DB::table('price_bars')->upsert(
                    $batch,
                    ['asset_id','date'],
                    ['open','high','low','close','volume']
                );
                $count += count($batch);
                $batch = [];               // free the batch
                gc_collect_cycles();       // hint GC
            }
        }

        // flush tail
        if (!empty($batch)) {
            DB::table('price_bars')->upsert(
                $batch,
                ['asset_id','date'],
                ['open','high','low','close','volume']
            );
            $count += count($batch);
        }

        $this->command?->info("Seeded {$count} rows for {$symbol}");
    }

    /**
     * Retrieve existing asset id or create a new asset record.
     */
    private function getOrCreateAssetId(string $symbol): int
    {
        $existing = DB::table('assets')->where('symbol', $symbol)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('assets')->insertGetId([
            'symbol' => $symbol,
            'name'   => $symbol,
        ]);
    }

    /**
     * Stream CSV rows to avoid loading the entire file into memory.
     */
    private function streamCsv(string $path): \Generator
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
    private function parseCsv(string $path): array
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
    private function pick(array $row, array $keys): ?string
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
    private function normDate(?string $raw): ?string
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
    private function num(array $row, string $key): ?float
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
    private function vol(array $row, string $key): ?int
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
