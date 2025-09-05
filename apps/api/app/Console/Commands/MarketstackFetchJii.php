<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * php artisan marketstack:fetch-jii --date-from=2025-08-01 --date-to=2025-08-29 --limit=1000 --chunk=200 --csv
 */
class MarketstackFetchJii extends Command
{
    protected $signature = 'marketstack:fetch-jii
        {--date-from= : Start date, e.g. 2024-01-01}
        {--date-to= : End date, e.g. 2025-08-15}
        {--limit=1000 : Page size per API call}
        {--chunk=200 : DB upsert batch size}
        {--csv : Also update CSVs in storage/app/historical}
        {--dry-run : Parse but don\'t write to DB/CSV}';

    protected $description = 'Fetch EOD for all 30 JII symbols from Marketstack, upsert DB, and optionally update CSVs';

    /** full list of 30 JII symbols with .XIDX suffix */
    private array $symbols = [
        'ADRO.XIDX','AKRA.XIDX','AMMN.XIDX','ANTM.XIDX','ASII.XIDX',
        'BRIS.XIDX','BRMS.XIDX','BRPT.XIDX','CPIN.XIDX','EXCL.XIDX',
        'ICBP.XIDX','INCO.XIDX','INDF.XIDX','INKP.XIDX','ISAT.XIDX',
        'KLBF.XIDX','MAPI.XIDX','MBMA.XIDX','MDKA.XIDX','MEDC.XIDX',
        'PANI.XIDX','PGAS.XIDX','PGEO.XIDX','PTBA.XIDX','PTRO.XIDX',
        'SMGR.XIDX','TLKM.XIDX','TPIA.XIDX','UNTR.XIDX','UNVR.XIDX',
    ];

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        @set_time_limit(0);

        $base = env('MARKETSTACK_BASE', 'https://api.marketstack.com/v2');
        $key  = env('MARKETSTACK_KEY');
        if (!$key) {
            $this->error('MARKETSTACK_KEY missing in .env');
            return self::FAILURE;
        }

        $dateFrom = $this->option('date-from');
        $dateTo   = $this->option('date-to');
        $limit    = (int) $this->option('limit');
        $chunk    = (int) $this->option('chunk');
        $dry      = (bool) $this->option('dry-run');
        $wantCsv  = (bool) $this->option('csv');
        $csvDir   = storage_path('app/historical');

        if ($wantCsv && !is_dir($csvDir)) {
            mkdir($csvDir, 0755, true);
        }

        $endpoint = rtrim($base, '/') . '/eod';
        $offset = 0; $page = 1; $total = null;
        $insertCount = 0;

        do {
            $query = [
                'access_key' => $key,
                'symbols'    => implode(',', $this->symbols),
                'limit'      => $limit,
                'offset'     => $offset,
            ];
            if ($dateFrom) $query['date_from'] = $dateFrom;
            if ($dateTo)   $query['date_to']   = $dateTo;

            $resp = Http::timeout(60)->retry(3, 500)->get($endpoint, $query);
            if ($resp->failed()) {
                $this->error("HTTP error: {$resp->status()} {$resp->body()}");
                return self::FAILURE;
            }
            $json = $resp->json();
            $pagination = $json['pagination'] ?? [];
            $count = $pagination['count'] ?? count($json['data']);
            $total = $total ?? ($pagination['total'] ?? $count);

            $this->info("Page {$page} | got {$count} rows (offset={$offset}/total≈{$total})");

            $dbBatch = [];
            $csvBuffers = [];

            foreach ($json['data'] as $row) {
                $symFull = $row['symbol'] ?? null;
                $ymd     = $this->ymdFromIso($row['date'] ?? null);
                if (!$symFull || !$ymd) continue;

                $baseSym = strtok($symFull, '.');
                $assetId = $this->getOrCreateAssetId($baseSym);

                $open   = $row['open'] ?? null;
                $high   = $row['high'] ?? null;
                $low    = $row['low'] ?? null;
                $close  = $row['close'] ?? null;
                $volume = $row['volume'] ?? null;

                $dbBatch[] = [
                    'asset_id'   => $assetId,
                    'date'       => $ymd,
                    'open'       => $open,
                    'high'       => $high,
                    'low'        => $low,
                    'close'      => $close,
                    'volume'     => $volume,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($dbBatch) >= $chunk) {
                    if (!$dry) DB::table('prices')->upsert($dbBatch, ['asset_id','date'],
                        ['open','high','low','close','volume','updated_at']);
                    $insertCount += count($dbBatch);
                    $dbBatch = [];
                }

                if ($wantCsv && !$dry) {
                    $csvBuffers[$baseSym][] = "{$ymd},{$open},{$high},{$low},{$close},{$volume}\n";
                    if (count($csvBuffers[$baseSym]) >= 500) {
                        $this->appendCsv($csvDir, $baseSym, $csvBuffers[$baseSym]);
                        $csvBuffers[$baseSym] = [];
                    }
                }
            }

            if (!empty($dbBatch)) {
                if (!$dry) DB::table('prices')->upsert($dbBatch, ['asset_id','date'],
                    ['open','high','low','close','volume','updated_at']);
                $insertCount += count($dbBatch);
            }

            if ($wantCsv && !$dry) {
                foreach ($csvBuffers as $sym => $lines) {
                    if (!empty($lines)) $this->appendCsv($csvDir, $sym, $lines);
                }
            }

            $offset += $limit; $page++;
        } while ($offset < $total);

        $this->info(($dry ? '[dry-run] ' : '')."Done. Upserted {$insertCount} rows.");
        if ($wantCsv && !$dry) $this->info("CSVs updated in {$csvDir}");
        return self::SUCCESS;
    }

    private function ymdFromIso(?string $iso): ?string
    {
        return $iso ? substr($iso, 0, 10) : null;
    }

    private function getOrCreateAssetId(string $symbol): int
    {
        $id = DB::table('assets')->where('symbol', $symbol)->value('id');
        if ($id) return (int)$id;
        return (int) DB::table('assets')->insertGetId([
            'symbol' => $symbol,
            'name' => $symbol,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appendCsv(string $dir, string $baseSym, array $lines): void
    {
        $path = "{$dir}/{$baseSym}.csv";
        if (!file_exists($path)) {
            file_put_contents($path, "date,open,high,low,close,volume\n");
        }
        file_put_contents($path, implode('', $lines), FILE_APPEND);
    }
}
