<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SymbolDate;

/**
 * Display latest date per JII symbol and the chosen date_from value.
 */
class MarketstackShowDateFrom extends Command
{
    protected $signature = 'marketstack:show-date-from';

    protected $description = 'Show latest DB/CSV date for each JII symbol and overall date_from';

    public function handle(): int
    {
        $baseSymbols = config('csv.index_symbols', []);
        $latestDates = [];

        foreach ($baseSymbols as $sym) {
            $dates = SymbolDate::latest($sym);
            $latestDates[$sym] = $dates['latest'];
            $this->line(sprintf('%-6s db:%s csv:%s latest:%s',
                $sym,
                $dates['db'] ?? '-',
                $dates['csv'] ?? '-',
                $dates['latest']));
        }

        if (!empty($latestDates)) {
            $this->info('date_from = '.min($latestDates));
        } else {
            $this->warn('No symbols configured.');
        }

        return self::SUCCESS;
    }
}
