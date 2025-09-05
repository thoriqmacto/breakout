<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SymbolDate
{
    /**
     * Determine the latest date available for a symbol from DB and CSV.
     *
     * @param string     $symbol   Base symbol (without .XIDX)
     * @param array|null $csvRows  Optional CSV rows keyed by date
     * @return array{db:?string,csv:?string,latest:string}
     */
    public static function latest(string $symbol, ?array $csvRows = null): array
    {
        $csvDir = config('csv.seed_dir');
        if ($csvRows === null) {
            $path = "{$csvDir}/{$symbol}.csv";
            $csvRows = CsvBars::read($path);
        }
        $csvLast = $csvRows ? max(array_keys($csvRows)) : null;

        $dbLast = null;
        if (Schema::hasTable('prices') && Schema::hasTable('assets')) {
            $dbLast = DB::table('prices')
                ->join('assets', 'assets.id', '=', 'prices.asset_id')
                ->where('assets.symbol', $symbol)
                ->max('prices.date');
        }

        if ($dbLast && $csvLast) {
            $latest = max($dbLast, $csvLast);
        } else {
            $latest = $dbLast ?? $csvLast;
        }

        return [
            'db'     => $dbLast,
            'csv'    => $csvLast,
            'latest' => $latest ?: '2000-01-01',
        ];
    }
}
