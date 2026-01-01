<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SymbolDate;
use App\Support\AssetList;

/**
 * Display latest date per JII symbol and the chosen date_from value.
 */
class MarketstackShowDateFrom extends Command
{
    protected $signature = 'marketstack:show-date-from {--chk-latest=}';

    protected $description = 'Show latest DB/CSV date and total bars for each JII symbol and overall date_from';

    public function handle(): int
    {
        $baseSymbols = AssetList::symbols();
        $latestDates = [];
        $chkLatest   = $this->option('chk-latest');

        foreach ($baseSymbols as $sym) {
            $dates = SymbolDate::latest($sym, null, $chkLatest);
            $latestDates[$sym] = $dates['latest'];

            $this->line(sprintf('%-6s db:%s csv:%s latest:%s total:%d is_latest:%s',
                $sym,
                $dates['db'] ?? '-',
                $dates['csv'] ?? '-',
                $dates['latest'],
                $dates['total'],
                $dates['is_latest'] ?? '-'));
        }

        if (!empty($latestDates)) {
            $this->info('date_from = '.min($latestDates));
        } else {
            $this->warn('No symbols configured.');
        }

        return self::SUCCESS;
    }
}
