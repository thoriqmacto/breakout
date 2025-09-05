<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SymbolDate;

/**
 * Display latest date per JII symbol and the chosen date_from value.
 */
class MarketstackShowDateFrom extends Command
{
    protected $signature = 'marketstack:show-date-from {--chk-latest=}';

    protected $description = 'Show latest DB/CSV date and total bars for each JII symbol and overall date_from';

    public function handle(): int
    {
        $baseSymbols = config('csv.index_symbols', []);
        $latestDates = [];
        $chkLatest   = $this->option('chk-latest');

        foreach ($baseSymbols as $sym) {
            $dates = SymbolDate::latest($sym);
            $latestDates[$sym] = $dates['latest'];
            $this->line(sprintf('%-6s db:%s csv:%s latest:%s total:%d',
                $sym,
                $dates['db'] ?? '-',
                $dates['csv'] ?? '-',
                $dates['latest'],
                $dates['total']));
        }

        if (!empty($latestDates)) {
            $this->info('date_from = '.min($latestDates));
            if ($chkLatest) {
                $allMatch = !array_diff($latestDates, [$chkLatest]);
                $this->info('latest-compare = '.($allMatch ? 'yes' : 'no'));
            }
        } else {
            $this->warn('No symbols configured.');
        }

        return self::SUCCESS;
    }
}
