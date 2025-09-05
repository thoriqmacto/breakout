<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * php artisan marketstack:fetch-jii --limit=1000 --chunk=200 --csv
 * php artisan marketstack:fetch-jii --show-query
 */
class MarketstackFetchJii extends Command
{
    protected $signature = 'marketstack:fetch-jii
        {--limit=1000 : Page size per API call}
        {--chunk= : DB upsert batch size (defaults to config/csv.php)}
        {--csv : Also update CSVs in storage/app/historical}
        {--dry-run : Parse but don\'t write to DB/CSV}
        {--show-query : Output the API URL and exit}';

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

        $limit     = (int) $this->option('limit');
        $chunk     = (int) ($this->option('chunk') ?: config('csv.chunk_size'));
        $dry       = (bool) $this->option('dry-run');
        $wantCsv   = (bool) $this->option('csv');
        $showQuery = (bool) $this->option('show-query');
        $csvDir    = config('csv.seed_dir');

        $baseSymbols = config('csv.index_symbols', []);
        $symbols     = array_map(fn ($s) => $s . '.XIDX', $baseSymbols);

        $csvDates = [];
        $latestDates = [];

        foreach ($baseSymbols as $sym) {
            [$dates, $csvLast] = $this->loadCsvDates("{$csvDir}/{$sym}.csv");
            $csvDates[$sym] = $dates;

            $dbLast = null;
            if (Schema::hasTable('prices') && Schema::hasTable('assets')) {
                $dbLast = DB::table('prices')
                    ->join('assets', 'assets.id', '=', 'prices.asset_id')
                    ->where('assets.symbol', $sym)
                    ->max('prices.date');
            }

            if ($dbLast && $csvLast) {
                $latest = max($dbLast, $csvLast);
            } else {
                $latest = $dbLast ?? $csvLast;
            }

            $latestDates[$sym] = $latest ?: '2000-01-01';
        }

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
            $csvBuffers = [];

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
                        DB::table('prices')->upsert($dbBatch, ['asset_id', 'date'],
                            ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
                    }
                    $insertCount += count($dbBatch);
                    $dbBatch = [];
                }

                if ($wantCsv && !$dry && !isset($csvDates[$baseSym][$ymd])) {
                    $csvDates[$baseSym][$ymd] = true;
                    $csvBuffers[$baseSym][] = "{$ymd},{$open},{$high},{$low},{$close},{$volume}\n";
                    if (count($csvBuffers[$baseSym]) >= 500) {
                        $this->appendCsv($csvDir, $baseSym, $csvBuffers[$baseSym]);
                        $csvBuffers[$baseSym] = [];
                    }
                }
            }

            if (!empty($dbBatch)) {
                if (!$dry) {
                    DB::table('prices')->upsert($dbBatch, ['asset_id', 'date'],
                        ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
                }
                $insertCount += count($dbBatch);
            }

            if ($wantCsv && !$dry) {
                foreach ($csvBuffers as $sym => $lines) {
                    if (!empty($lines)) {
                        $this->appendCsv($csvDir, $sym, $lines);
                    }
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

    /**
     * Load existing CSV dates for a symbol and return [dateSet, latestDate].
     *
     * @param string $path
     * @return array{array<string,bool>, string|null}
     */
    private function loadCsvDates(string $path): array
    {
        $dates = [];
        $latest = null;
        if (file_exists($path)) {
            if (($fh = fopen($path, 'r')) !== false) {
                $first = true;
                while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                    if ($first) { $first = false; continue; }
                    $d = $row[0] ?? null;
                    if (!$d) continue;
                    $dates[$d] = true;
                    if (!$latest || $d > $latest) {
                        $latest = $d;
                    }
                }
                fclose($fh);
            }
        }
        return [$dates, $latest];
    }
}
