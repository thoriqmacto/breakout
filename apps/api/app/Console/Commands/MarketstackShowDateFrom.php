<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Display latest date per JII symbol and the chosen date_from value.
 */
class MarketstackShowDateFrom extends Command
{
    protected $signature = 'marketstack:show-date-from';

    protected $description = 'Show latest DB/CSV date for each JII symbol and overall date_from';

    public function handle(): int
    {
        $csvDir      = config('csv.seed_dir');
        $baseSymbols = config('csv.index_symbols', []);
        $latestDates = [];

        foreach ($baseSymbols as $sym) {
            $csvLast = $this->csvLatest("{$csvDir}/{$sym}.csv");

            $dbLast = null;
            if (Schema::hasTable('price_bars') && Schema::hasTable('assets')) {
                $dbLast = DB::table('price_bars')
                    ->join('assets', 'assets.id', '=', 'price_bars.asset_id')
                    ->where('assets.symbol', $sym)
                    ->max('price_bars.date');
            }

            if ($dbLast && $csvLast) {
                $latest = max($dbLast, $csvLast);
            } else {
                $latest = $dbLast ?? $csvLast;
            }
            $latest = $latest ?: '2000-01-01';

            $latestDates[$sym] = $latest;
            $this->line(sprintf('%-6s db:%s csv:%s latest:%s',
                $sym,
                $dbLast ?? '-',
                $csvLast ?? '-',
                $latest));
        }

        if (!empty($latestDates)) {
            $this->info('date_from = '.min($latestDates));
        } else {
            $this->warn('No symbols configured.');
        }

        return self::SUCCESS;
    }

    private function csvLatest(string $path): ?string
    {
        $latest = null;
        if (file_exists($path)) {
            if (($fh = fopen($path, 'r')) !== false) {
                $first = true;
                while (($row = fgetcsv($fh)) !== false) {
                    if ($first) { $first = false; continue; }
                    $d = $row[0] ?? null;
                    if ($d && (!$latest || $d > $latest)) {
                        $latest = $d;
                    }
                }
                fclose($fh);
            }
        }
        return $latest;
    }
}
