<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CsvBars;
use App\Services\SymbolDate;

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
                foreach ($missing as $sym) {
                    $pyFile = $pyDir . '/' . $sym . '_PY.csv';
                    if (is_file($pyFile)) {
                        $this->line("Found python csv for {$sym}: " . basename($pyFile));
                    } else {
                        $this->warn("Python csv not found for {$sym}");
                    }
                }
            }

            if (! $this->confirm('Continue checking latest data anyway?', true)) {
                return Command::FAILURE;
            }
        }
        // Check latest data from CSV and DB for each configured symbol
        $rows = [];
        foreach ($indexSymbols as $symbol) {
            $csvPath = $seedDir . '/' . $symbol . '.csv';
            $csvRows = CsvBars::read($csvPath);
            $dates   = SymbolDate::latest($symbol, $csvRows);

            $rows[] = [
                $symbol,
                $dates['csv'] ?? 'n/a',
                $dates['db'] ?? 'n/a',
                $dates['total'] ?? 'n/a',
            ];
        }

        $this->table(['Symbol', 'CSV Latest', 'DB Latest', 'Total Bars'], $rows);

        return Command::SUCCESS;
    }
}
