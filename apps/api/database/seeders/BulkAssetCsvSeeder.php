<?php

namespace Database\Seeders;

use App\Services\CsvUtilities;
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

        $rows = CsvUtilities::streamCsv($path); // generator (no big arrays)

        $batch = [];
        $count = 0;

        foreach ($rows as $r) {
            $rawDate = CsvUtilities::pick($r, ['date','Date','DATE']);
            $date = CsvUtilities::normDate($rawDate);
            if (!$date) {
                continue;
            }

            $batch[] = [
                'asset_id' => $assetId,
                'date'     => $date,
                'open'     => CsvUtilities::num($r, 'open'),
                'high'     => CsvUtilities::num($r, 'high'),
                'low'      => CsvUtilities::num($r, 'low'),
                'close'    => CsvUtilities::num($r, 'close'),
                'volume'   => CsvUtilities::vol($r, 'volume'),
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

}
