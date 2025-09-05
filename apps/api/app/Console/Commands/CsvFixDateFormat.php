<?php

namespace App\Console\Commands;

use App\Services\CsvBars;
use Illuminate\Console\Command;

class CsvFixDateFormat extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'csv:fix-date-format {--dir=}';

    /**
     * The console command description.
     */
    protected $description = 'Normalize CSV files using the CsvBars service.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dir = $this->option('dir') ?: base_path();
        if (!is_dir($dir)) {
            $this->error("Directory not found: {$dir}");
            return self::FAILURE;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $processed = 0;

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'csv') {
                continue;
            }

            $path = $file->getPathname();
            $rows = CsvBars::read($path);
            if ($rows === []) {
                continue;
            }

            CsvBars::write($path, $rows);
            $this->line("Updated: {$path}");
            $processed++;
        }

        $this->info("Processed {$processed} CSV file(s).");
        return self::SUCCESS;
    }
}

