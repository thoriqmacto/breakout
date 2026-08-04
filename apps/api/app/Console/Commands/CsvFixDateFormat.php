<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MirrorsSeedCsvs;
use App\Services\CsvBars;
use Illuminate\Console\Command;

class CsvFixDateFormat extends Command
{
    use MirrorsSeedCsvs;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'csv:fix-date-format
        {--dir=}
        {--disk= : Mirror disk for the seed CSVs (default: CSV_MIRROR_DISK; empty disables mirroring)}';

    /**
     * The console command description.
     */
    protected $description = 'Normalize CSV files using the CsvBars service.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info($this->description);
        $dir = $this->option('dir') ?: base_path();
        if (! is_dir($dir)) {
            $this->error("Directory not found: {$dir}");

            return self::FAILURE;
        }

        // Only bookend with the mirror when this run actually covers the seed
        // directory; --dir can point anywhere, and rewriting unrelated CSVs is
        // no reason to touch the durable copy of the bars.
        $mirrors = $this->coversSeedDirectory($dir);

        if ($mirrors) {
            $this->hydrateSeedCsvs();
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $processed = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'csv') {
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

        if ($mirrors) {
            $this->flushSeedCsvs();
        }

        return self::SUCCESS;
    }

    /**
     * Whether the directory being normalized contains the seed CSVs.
     */
    private function coversSeedDirectory(string $dir): bool
    {
        $seedDir = (string) config('csv.seed_dir');

        if ($seedDir === '') {
            return false;
        }

        $target = realpath($dir);
        $seed = realpath($seedDir);

        if ($target === false || $seed === false) {
            return false;
        }

        return $seed === $target || str_starts_with($seed, rtrim($target, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
