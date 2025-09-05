<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\CsvBars;
use App\Services\SymbolDate;

/**
 * php artisan marketstack:fetch-jii --limit=1000 --chunk=200 --csv
 * php artisan marketstack:fetch-jii --show-query
 * php artisan marketstack:fetch-jii --chk-latest=2024-01-01
 */
class MarketstackFetchJii extends Command
{
    protected $signature = 'marketstack:fetch-jii
        {--limit=1000 : Page size per API call}
        {--chunk= : DB upsert batch size (defaults to config/csv.php)}
        {--csv : Also update CSVs in database/seeders/data/historical}
        {--dry-run : Parse but don\'t write to DB/CSV}
        {--show-query : Output the API URL and exit}
        {--chk-latest= : Skip symbols already up to date for the given date}';

    protected $description = 'Fetch EOD for all 30 JII symbols from Marketstack, upsert DB, and optionally update CSVs';

    /**
     * Cached list of asset IDs keyed by base symbol (without .XIDX).
     * Used to avoid repetitive lookups when processing API results.
     */
    private array $assetIds = [];

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

        $limit      = (int) $this->option('limit');
        $chunk      = (int) ($this->option('chunk') ?: config('csv.chunk_size'));
        $dry        = (bool) $this->option('dry-run');
        $wantCsv    = (bool) $this->option('csv');
        $showQuery  = (bool) $this->option('show-query');
        $chkLatest  = $this->option('chk-latest');
        $csvDir     = config('csv.seed_dir');

        $baseSymbols    = config('csv.index_symbols', []);
        $symbolsToFetch = [];
        $csvRows        = [];
        $latestDates    = [];

        foreach ($baseSymbols as $sym) {
            $path = "{$csvDir}/{$sym}.csv";
            $csvRows[$sym] = CsvBars::read($path);
            $dates = SymbolDate::latest($sym, $csvRows[$sym], $chkLatest);
            if (!$chkLatest || ($dates['is_latest'] ?? 'no') !== 'yes') {
                $symbolsToFetch[] = $sym;
                $latestDates[$sym] = $dates['latest'];
            }
        }

        if ($chkLatest && empty($symbolsToFetch)) {
            $this->info('All symbols are up to date. No API call required.');
            return self::SUCCESS;
        }

        $symbols = array_map(fn ($s) => $s . '.XIDX', $symbolsToFetch);

        $dateFrom = min($latestDates);
        $dateTo   = now()->toDateString();

        if ($wantCsv && !is_dir($csvDir)) {
            mkdir($csvDir, 0755, true);
        }

        $endpoint = rtrim($base, '/') . '/eod';
        $offset = 0; $page = 1; $total = null;
        $insertCount = 0;

        do {
            $query = [
                'access_key' => $key,
                'symbols'    => implode(',', $symbols),
                'limit'      => $limit,
                'offset'     => $offset,
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
            ];

            $queryString = strtr(http_build_query($query), [
                '%2C' => ',',
                '%2D' => '-',
                '%2F' => '-',
            ]);

            if ($showQuery) {
                $this->info($endpoint . '?' . $queryString);
                return self::SUCCESS;
            }

            $resp = Http::timeout(60)->retry(3, 500)->get($endpoint . '?' . $queryString);
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

            foreach ($json['data'] as $row) {
                $symFull = $row['symbol'] ?? null;
                $ymd     = $this->ymdFromIso($row['date'] ?? null);
                if (!$symFull || !$ymd) continue;

                $baseSym = strtok($symFull, '.');
                $assetId = $this->assetIds[$baseSym] ??= $this->getOrCreateAssetId($baseSym);

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
                    if (!$dry) {
                        DB::table('price_bars')->upsert($dbBatch, ['asset_id', 'date'],
                            ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
                    }
                    $insertCount += count($dbBatch);
                    $dbBatch = [];
                }

                if ($wantCsv && !$dry) {
                    $csvRows[$baseSym][$ymd] = [
                        'date'   => $ymd,
                        'open'   => $open,
                        'high'   => $high,
                        'low'    => $low,
                        'close'  => $close,
                        'volume' => $volume,
                    ];
                }
            }

            if (!empty($dbBatch)) {
                if (!$dry) {
                    DB::table('price_bars')->upsert($dbBatch, ['asset_id', 'date'],
                        ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
                }
                $insertCount += count($dbBatch);
            }

            // no CSV writing here; handled after loop

            $offset += $limit; $page++;
        } while ($offset < $total);

        if ($wantCsv && !$dry) {
            foreach ($csvRows as $sym => $rows) {
                $path = "{$csvDir}/{$sym}.csv";
                CsvBars::write($path, $rows);
            }
        }

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

}
