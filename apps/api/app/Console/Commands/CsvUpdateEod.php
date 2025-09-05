<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Asset; use App\Models\Price;
use App\Services\CsvBars;

class CsvUpdateEod extends Command
{
    protected $signature = 'csv:update-eod {--symbols=} {--since=} {--dir=}';
    protected $description = 'Fetch EOD for assets and update DB + local CSV (dedup).';

    public function handle()
    {
        $dir = $this->option('dir') ?: config('CsvToSeeder.seed_dir');
        $symbolsOpt = $this->option('symbols');
        $sinceOpt = $this->option('since'); // YYYY-MM-DD; if null, infer from DB last date

        $assetsQ = Asset::query();
        if ($symbolsOpt) {
            $list = array_map('trim', explode(',', strtoupper($symbolsOpt)));
            $assetsQ->whereIn('symbol', $list);
        }
        $assets = $assetsQ->get();
        if ($assets->isEmpty()) { $this->warn('No assets found.'); return self::SUCCESS; }

        foreach ($assets as $asset) {
            $symbol = $asset->symbol;
            $this->info("Updating $symbol …");

            // Determine period1 from DB last date
            $last = Price::where('asset_id',$asset->id)->max('date'); // Y-m-d
            $since = $sinceOpt ?: ($last ? date('Y-m-d', strtotime($last.' +1 day')) : '1970-01-01');

            // Period determining
            $period1 = strtotime($since);
            $period2 = time();
            if ($period1 >= $period2) { $this->line('  up to date.'); continue; }

            // Yahoo CSV download (note: may rate-limit; consider a paid API in prod)
            //$url = "https://query1.finance.yahoo.com/v7/finance/download/{$symbol}"
            //    . "?period1={$period1}&period2={$period2}&interval=1d&events=history&includeAdjustedClose=true";

            // Marketstack
            $symbol = $symbol . ".XIDX";
            $url = "http://api.marketstack.com/v2/eod?access_key=1e1fbeb7918fa9dace023f1b73c528fd&symbols={$symbol}"
                . "&date_from={$period1}&date_to={$period2}&limit=1000";

            $resp = Http::retry(3, 500)->get($url);
            if (!$resp->ok()) { $this->error("  fetch failed for $symbol"); continue; }

            // Parse downloaded CSV chunk
            $linez = array_map('str_getcsv', explode("\n", trim($resp->body())));
            $header = array_map('strtolower', array_shift($linez) ?: []);
            $newRows = [];
            foreach ($linez as $r) {
                if (count($r) < 6) continue;
                $rec = array_combine($header, $r);
                $date = substr($rec['date'] ?? '', 0, 10);
                if (!$date) continue;
                $row = [
                    'date'   => $date,
                    'open'   => (float)($rec['open'] ?? 0),
                    'high'   => (float)($rec['high'] ?? 0),
                    'low'    => (float)($rec['low'] ?? 0),
                    'close'  => (float)($rec['close'] ?? 0),
                    'volume' => (int)($rec['volume'] ?? 0),
                ];
                $newRows[$date] = $row;

                // Upsert DB
                Price::updateOrCreate(
                    ['asset_id'=>$asset->id, 'date'=>$date],
                    ['open'=>$row['open'], 'high'=>$row['high'], 'low'=>$row['low'],
                        'close'=>$row['close'], 'volume'=>$row['volume']]
                );
            }
            $this->line('  DB upserted: '.count($newRows).' rows');

            // Merge to local CSV
            $csvPath = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$symbol.'.csv';
            $existing = CsvBars::read($csvPath);
            $merged = CsvBars::merge($existing, $newRows);
            CsvBars::write($csvPath, $merged);
            $this->line('  CSV merged: ' . $csvPath . ' (total '.count($merged).' rows)');
        }

        return self::SUCCESS;
    }
}
