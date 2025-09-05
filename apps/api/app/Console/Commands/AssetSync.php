<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

            return Command::FAILURE;
        }

        // When all symbols are aligned, check latest data from CSV and DB
        $rows = [];
        foreach ($indexSymbols as $symbol) {
            $csvPath = $seedDir . '/' . $symbol . '.csv';
            $csvDate = $this->latestDateFromCsv($csvPath);

            $asset = Asset::where('symbol', $symbol)->first();
            $dbDate = null;
            if ($asset) {
                $price = $asset->latestPrice();
                $dbDate = $price?->date;
            }

            $rows[] = [
                $symbol,
                $csvDate?->toDateString() ?? 'n/a',
                $dbDate?->toDateString() ?? 'n/a',
            ];
        }

        $this->table(['Symbol', 'CSV Latest', 'DB Latest'], $rows);

        return Command::SUCCESS;
    }

    /**
     * Extract the latest date from a CSV file.
     */
    protected function latestDateFromCsv(string $path): ?Carbon
    {
        if (!is_file($path)) {
            return null;
        }

        $file = new \SplFileObject($path, 'r');
        // Move to last line
        $file->seek(PHP_INT_MAX);
        $line = trim($file->current());
        while ($line === '' && $file->key() > 0) {
            $file->seek($file->key() - 1);
            $line = trim($file->current());
        }
        if ($file->key() === 0) {
            return null; // Only header present
        }

        $data = str_getcsv($line);
        $dateString = $data[0] ?? null;
        if (!$dateString) {
            return null;
        }

        // Try multiple possible formats
        foreach (['Y-m-d', 'd/m/Y'] as $fmt) {
            $dt = Carbon::createFromFormat($fmt, $dateString);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
