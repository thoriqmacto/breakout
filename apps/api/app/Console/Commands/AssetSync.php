<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\CsvBars;
use App\Services\SymbolDate;
use App\Services\DbBars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AssetSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:sync
        {--check-python : Search python/csv for missing assets}
        {--run-python : Run python get_stocks.py when needed}
        {--import-csv : Import found python csv files into seed folder}
        {--continue : Skip confirmation before latest-data check}
        {--chk-date= : Custom date (YYYY-MM-DD) used for latest comparison}
        {--eod : Confirm all prompts and use today for chk-date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize historical price to DB and CSV from python API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info($this->description);

        if ($this->option('eod')) {
            $today = Carbon::now()->toDateString();
            $this->input->setOption('check-python', true);
            $this->input->setOption('run-python', true);
            $this->input->setOption('import-csv', true);
            $this->input->setOption('continue', true);
            $this->input->setOption('chk-date', $today);
        }

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
            $checkPython = $this->option('check-python') ??
                           $this->confirm('Check python csv folder for these assets?', false);
            if ($checkPython) {
                $pyDir = resource_path('python/csv');

                $found     = [];
                $missingPy = [];
                foreach ($missing as $sym) {
                    $pyFile = $pyDir . '/' . $sym . '_PY.csv';
                    if (is_file($pyFile)) {
                        $found[$sym] = $pyFile;
                    } else {
                        $missingPy[] = $sym;
                        $this->warn("Python csv not found for {$sym}");
                    }
                }

                if (!empty($missingPy)) {
                    $tickFile = resource_path('python/tickers.txt');

                    // Reset tickers file so it only contains the newly missing symbols
                    $tickers = array_map(fn($sym) => $sym . '.JK', $missingPy);
                    file_put_contents($tickFile, implode(PHP_EOL, $tickers) . PHP_EOL);

                    $this->info('Missing tickers written to tickers.txt: ' . implode(', ', $tickers));

                    $runPython = $this->option('run-python') ??
                                 $this->confirm('Run python get_stocks.py now?', false);
                    if ($runPython) {
                        $this->call('python:run', ['script' => 'get_stocks.py']);
                    } else {
                        $this->warn('No python code to run.');
                    }
                }

                if (!empty($found)) {
                    $this->info('Found python csv files:');
                    foreach ($found as $sym => $file) {
                        $this->line('- ' . $sym . ': ' . basename($file));
                    }

                    $importCsv = $this->option('import-csv') ??
                                 $this->confirm('Import these csv files into the seed directory?', false);
                    if ($importCsv) {
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
        } else{
            $this->comment('Tickers in config and seed files aligned.');
        }

        if (!$this->option('continue') &&
            !$this->confirm('Continue checking latest data anyway?', false)) {
            return Command::FAILURE;
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

        if ($dbBars->inserted()>0) {
            $this->info('Upserted ' . $dbBars->inserted() . ' rows into DB.');
        }

        $dbBars->flush();

        // Ask user for a custom date to check against latest data
        $chkLatest = $this->option('chk-date');
        $showIsLatest = (bool) $chkLatest;
        if (!$chkLatest && $this->confirm('Do you have your own chk-date to compare with latest-date data?', false)) {
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

            if ($chkLatest && Carbon::parse($dates['latest'])->lt(Carbon::parse($chkLatest))) {
                $start  = Carbon::parse($dates['latest'])->addDay()->toDateString();
                $end    = $chkLatest ?: now()->toDateString();
                $ticker = $symbol . '.JK';

                $this->call('python:run', [
                    'script' => 'get_stocks.py',
                    '--arg'  => ["--start={$start}", "--end={$end}", $ticker],
                ]);

                $pyFile = resource_path('python/csv/' . $symbol . '_PY.csv');
                if (is_file($pyFile)) {
                    $newRows = CsvBars::read($pyFile);
                    if ($newRows !== []) {
                        $existing = CsvBars::read($csvPath);
                        $merged   = CsvBars::merge($existing, $newRows);
                        CsvBars::write($csvPath, $merged);

                        $asset = Asset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);
                        $existingDates = DB::table('price_bars')
                            ->where('asset_id', $asset->id)
                            ->pluck('date')
                            ->all();
                        $existingDates = array_flip($existingDates);

                        $updBars = new DbBars($chunk, false);
                        foreach ($newRows as $ymd => $row) {
                            if (isset($existingDates[$ymd])) {
                                continue;
                            }

                            $updBars->add([
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
                        $updBars->flush();

                        $csvRows = $merged;
                        $dates   = SymbolDate::latest($symbol, $csvRows, $chkLatest);
                    }
                }
            }

            $row = [
                $i,
                $symbol,
                $dates['csv'] ?? 'n/a',
                $dates['db'] ?? 'n/a',
                $dates['total'] ?? 'n/a',
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

        return Command::SUCCESS;
    }
}
