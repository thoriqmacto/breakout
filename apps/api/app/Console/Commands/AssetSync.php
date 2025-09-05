<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\CsvBars;
use App\Services\SymbolDate;
use App\Services\DbBars;
use Illuminate\Support\Facades\DB;

class AssetSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize assets EOD price to db and csv from python runner. Config file always become symbols master.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info($this->description);

        $indexSymbols = config('csv.index_symbols', []);
        $seedDir = config('csv.seed_dir');

        // Collect CSV files available in seed directory
        $seedFiles = glob($seedDir . '/*.csv') ?: [];
        $csvSymbols = array_map(fn($f) => strtoupper(basename($f, '.csv')), $seedFiles);

        // Compare config symbols with available CSV files
        $missing = array_diff($indexSymbols, $csvSymbols);
        if (!empty($missing)) {
            $this->warn('CSV seed files missing for: ' . implode(', ', $missing));

            // Offer to check python csv directory for missing files
            if ($this->confirm('Check python csv folder for these assets?', false)) {
                $pyDir = resource_path('python/csv');

                $found = [];
                foreach ($missing as $sym) {
                    $pyFile = $pyDir . '/' . $sym . '_PY.csv';
                    if (is_file($pyFile)) {
                        $found[$sym] = $pyFile;
                    } else {
                        $this->warn("Python csv not found for {$sym}");
                    }
                }

                if (!empty($found)) {
                    $this->info('Found python csv files:');
                    foreach ($found as $sym => $file) {
                        $this->line('- ' . $sym . ': ' . basename($file));
                    }

                    if ($this->confirm('Import these csv files into the seed directory?', false)) {
                        foreach ($found as $sym => $pyFile) {
                            $rows = CsvBars::read($pyFile);
                            if ($rows !== []) {
                                $dest = $seedDir . '/' . $sym . '.csv';
                                $existing = CsvBars::read($dest);
                                $merged = CsvBars::merge($existing, $rows);
                                CsvBars::write($dest, $merged);
                                $this->info('Seed file written: ' . basename($dest));
                            } else {
                                $this->warn("No data in python csv for {$sym}");
                            }
                        }

                        // Re-scan seed directory after attempting to import
                        $seedFiles = glob($seedDir . '/*.csv') ?: [];
                        $csvSymbols = array_map(fn($f) => strtoupper(basename($f, '.csv')), $seedFiles);
                        $missing = array_diff($indexSymbols, $csvSymbols);
                    }
                }
            }

            if (! $this->confirm('Continue checking latest data anyway?', true)) {
                return Command::FAILURE;
            }
        }
        // Upsert missing bars from CSV into DB for each symbol
        $chunk = (int) config('csv.chunk_size', 200);
        $dbBars = new DbBars($chunk, false);

        foreach ($indexSymbols as $symbol) {
            $csvPath = $seedDir . '/' . $symbol . '.csv';
            if (!is_file($csvPath)) {
                // skip symbols without seed CSV
                continue;
            }

            // Ensure asset exists in DB
            $asset = Asset::firstOrCreate(
                ['symbol' => $symbol],
                ['name' => $symbol]
            );

            $csvRows = CsvBars::read($csvPath);

            // Existing DB dates for the asset
            $existing = DB::table('price_bars')
                ->where('asset_id', $asset->id)
                ->pluck('date')
                ->all();
            $existing = array_flip($existing);

            foreach ($csvRows as $ymd => $row) {
                if (isset($existing[$ymd])) {
                    continue; // already present
                }

                $dbBars->add([
                    'asset_id'   => $asset->id,
                    'date'       => $ymd,
                    'open'       => $row['open'],
                    'high'       => $row['high'],
                    'low'        => $row['low'],
                    'close'      => $row['close'],
                    'volume'     => $row['volume'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $dbBars->flush();

        // Ask user for a custom date to check against latest data
        $chkLatest = null;
        $showIsLatest = false;
        if ($this->confirm('Do you have your own chk-date to compare with latest-date data?', false)) {
            $chkLatest = $this->ask('Enter chk-date (YYYY-MM-DD)');
            $showIsLatest = true;
        }

        // After syncing, display latest data from CSV and DB for each symbol
        $rows = [];
        $i = 1;
        foreach ($indexSymbols as $symbol) {
            $csvPath = $seedDir . '/' . $symbol . '.csv';
            $csvRows = CsvBars::read($csvPath);
            $dates   = SymbolDate::latest($symbol, $csvRows, $chkLatest);

            $row = [
                $i,
                $symbol,
                $dates['csv'] ?? 'n/a',
                $dates['db'] ?? 'n/a',
                $dates['total'] ?? 'n/a',
                $dates['is_latest'] ?? 'no',
            ];

            if ($showIsLatest) {
                $row[] = $dates['is_latest'] ?? 'no';
            }

            $rows[] = $row;

            $i++;
        }

        $headers = ['No','Symbol', 'CSV Latest', 'DB Latest', 'Total Bars'];
        if ($showIsLatest) {
            $headers[] = 'Is Latest?';
        }

        $this->table($headers, $rows);

        $this->info('Upserted ' . $dbBars->inserted() . ' rows into DB.');

        return Command::SUCCESS;
    }
}
