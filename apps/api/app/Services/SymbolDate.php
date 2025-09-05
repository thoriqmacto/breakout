<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SymbolDate
{
    /**
     * Determine the latest date available for a symbol from DB and CSV and count bars.
     *
     * @param string     $symbol   Base symbol (without .XIDX)
     * @param array|null $csvRows  Optional CSV rows keyed by date
     * @return array{db:?string,csv:?string,latest:string,db_total:int,csv_total:int,total:int}
     */
    public static function latest(string $symbol, ?array $csvRows = null): array
    {
        $csvDir = config('csv.seed_dir');
        if ($csvRows === null) {
            $path = "{$csvDir}/{$symbol}.csv";
            $csvRows = CsvBars::read($path);
        }

        $csvDates = array_keys($csvRows);
        $csvLast  = $csvDates ? max($csvDates) : null;
        $csvTotal = count($csvDates);

        $dbDates = [];
        if (Schema::hasTable('price_bars') && Schema::hasTable('assets')) {
            $dbDates = DB::table('price_bars')
                ->join('assets', 'assets.id', '=', 'price_bars.asset_id')
                ->where('assets.symbol', $symbol)
                ->pluck('price_bars.date')
                ->toArray();
        }
        $dbLast  = $dbDates ? max($dbDates) : null;
        $dbTotal = count($dbDates);

        $allDates = array_unique(array_merge($dbDates, $csvDates));
        $latest   = $allDates ? max($allDates) : null;
        $total    = count($allDates);

        return [
            'db'       => $dbLast,
            'csv'      => $csvLast,
            'latest'   => $latest ?: '2000-01-01',
            'db_total' => $dbTotal,
            'csv_total' => $csvTotal,
            'total'    => $total,
        ];
    }
}
