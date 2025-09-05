<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MarketstackIngestEod extends Command
{
    protected $signature = 'marketstack:ingest-eod
        {--symbols= : Comma-separated list (max ~30), e.g. ANTM.XIDX,AKRA.XIDX}
        {--date-from= : Inclusive start, e.g. 2024-01-01}
        {--date-to= : Inclusive end, e.g. 2025-08-15}
        {--limit=1000 : Marketstack page size (max 1000)}
        {--chunk=200 : DB upsert chunk size}
        {--csv : Also update CSVs in storage/app/historical}
        {--csv-dir=storage/app/historical : Where to keep per-asset CSVs}
        {--dry-run : Parse but do not write DB/CSV}';

    protected $description = 'Fetch EOD from Marketstack for multiple symbols, upsert DB, and (optional) update per-asset CSVs';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        @set_time_limit(0);

        $base   = rtrim(config('services.marketstack.base', env('MARKETSTACK_BASE', 'https://api.marketstack.com/v2')), '/');
        $key    = config('services.marketstack.key', env('MARKETSTACK_KEY'));
        if (!$key) { $this->error('Missing MARKETSTACK_KEY.'); return self::FAILURE; }

        $symbolsArg = (string) ($this->option('symbols') ?? '');
        $symbols = collect(explode(',', $symbolsArg))->map(fn($s)=>trim($s))->filter()->values();
        if ($symbols->isEmpty()) { $this->error('Please supply --symbols=ANTM.XIDX,...'); return self::FAILURE; }

        $dateFrom = $this->option('date-from');
        $dateTo   = $this->option('date-to');
        $limit    = (int) $this->option('limit');
        $chunk    = max(50, (int) $this->option('chunk'));
        $dry      = (bool) $this->option('dry-run');

        $wantCsv  = (bool) $this->option('csv');
        $csvDir   = (string) $this->option('csv-dir');

        // Prepare CSV directory
        if ($wantCsv) {
            $diskPath = $this->ensureCsvDir($csvDir);
            if (!$diskPath) return self::FAILURE;
        }

        // Ensure assets exist; cache ids
        $assetId = [];
        foreach ($symbols as $symFull) {
            $assetId[$symFull] = $this->getOrCreateAssetId($this->baseSymbol($symFull));
        }

        // For CSV de-dup: build date-set per base symbol by scanning existing files once.
        $csvDates = []; // baseSymbol => ['YYYY-MM-DD' => true, ...]
        if ($wantCsv && !$dry) {
            foreach ($symbols as $symFull) {
                $baseSym = $this->baseSymbol($symFull);
                $csvDates[$baseSym] = $this->loadExistingCsvDates($csvDir, $baseSym);
            }
        }

        $endpoint = "{$base}/eod";
        $offset = 0; $total = null; $page = 1;
        $insertCount = 0;

        do {
            $query = [
                'access_key' => $key,
                'symbols'    => $symbols->implode(','),
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
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                $this->error('Unexpected Marketstack response shape.');
                return self::FAILURE;
            }

            $pagination = $json['pagination'] ?? [];
            $count = (int) ($pagination['count'] ?? count($json['data']));
            $total = $total ?? (int) ($pagination['total'] ?? $count);
            $this->info("Page {$page} | received {$count} rows (offset={$offset}/total≈{$total})");

            $dbBatch = [];
            $csvBuffers = []; // baseSymbol => ["date,open,high,low,close,volume\n", ...]

            foreach ($json['data'] as $row) {
                // Fields per your sample. :contentReference[oaicite:1]{index=1}
                $symFull = (string)($row['symbol'] ?? '');
                if ($symFull === '') continue;

                $baseSym = $this->baseSymbol($symFull);
                $id  = $assetId[$symFull] ??= $this->getOrCreateAssetId($baseSym);
                $ymd = $this->ymdFromIso($row['date'] ?? null);
                if (!$ymd) continue;

                $open   = $this->num($row['open']   ?? null);
                $high   = $this->num($row['high']   ?? null);
                $low    = $this->num($row['low']    ?? null);
                $close  = $this->num($row['close']  ?? null);
                $volume = $this->vol($row['volume'] ?? null);

                // === DB upsert batch ===
                $dbBatch[] = [
                    'asset_id'   => $id,
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
                    if (!$dry) DB::table('prices')->upsert(
                        $dbBatch, ['asset_id','date'], ['open','high','low','close','volume','updated_at']
                    );
                    $insertCount += count($dbBatch);
                    $dbBatch = [];
                }

                // === CSV append buffer (dedupe by date) ===
                if ($wantCsv && !$dry) {
                    $seen = $csvDates[$baseSym] ??= [];
                    if (!isset($seen[$ymd])) {
                        // remember new date to avoid duplicates in current run
                        $csvDates[$baseSym][$ymd] = true;
                        $csvBuffers[$baseSym][] = $this->csvLine($ymd, $open, $high, $low, $close, $volume);
                        // flush per symbol when buffer gets big
                        if (count($csvBuffers[$baseSym]) >= 500) {
                            $this->appendCsvLines($csvDir, $baseSym, $csvBuffers[$baseSym]);
                            $csvBuffers[$baseSym] = [];
                        }
                    }
                }
            }

            // flush remaining db batch
            if (!empty($dbBatch)) {
                if (!$dry) DB::table('prices')->upsert(
                    $dbBatch, ['asset_id','date'], ['open','high','low','close','volume','updated_at']
                );
                $insertCount += count($dbBatch);
                $dbBatch = [];
            }

            // flush all CSV buffers
            if ($wantCsv && !$dry) {
                foreach ($csvBuffers as $baseSym => $lines) {
                    if (!empty($lines)) {
                        $this->appendCsvLines($csvDir, $baseSym, $lines);
                        $csvBuffers[$baseSym] = [];
                    }
                }
            }

            $offset += $limit; $page++;
        } while ($offset < $total);

        $this->info(($dry ? '[dry-run] ' : '')."Done. Upserted rows: {$insertCount}.");
        if ($wantCsv && !$dry) $this->info("CSV updated in {$csvDir}.");
        return self::SUCCESS;
    }

    /* ------------------- Helpers ------------------- */

    private function ensureCsvDir(string $csvDir): ?string
    {
        // $csvDir is a path relative to project root (like storage/app/historical)
        $abs = base_path($csvDir);
        if (!is_dir($abs)) {
            if (!mkdir($abs, 0755, true) && !is_dir($abs)) {
                $this->error("Failed to create CSV dir: {$abs}");
                return null;
            }
        }
        return $abs;
    }

    private function baseSymbol(string $full): string
    {
        $pos = strpos($full, '.');
        return $pos === false ? strtoupper($full) : strtoupper(substr($full, 0, $pos));
    }

    private function ymdFromIso(?string $iso): ?string
    {
        if (!$iso) return null;
        // marketstack: "YYYY-MM-DDTHH:MM:SS+0000"
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $iso)) return substr($iso, 0, 10);
        $t = strtotime($iso);
        return $t ? date('Y-m-d', $t) : null;
    }

    private function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (float)$v : null;
    }

    private function vol($v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int) round((float)$v);
    }

    private function getOrCreateAssetId(string $symbol): int
    {
        $id = DB::table('assets')->where('symbol', $symbol)->value('id');
        if ($id) return (int)$id;

        return (int) DB::table('assets')->insertGetId([
            'symbol' => $symbol,
            'name'   => $symbol,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Read existing CSV dates into a hash for fast de-dup; creates file with header if none */
    private function loadExistingCsvDates(string $csvDir, string $baseSym): array
    {
        $path = base_path($csvDir . '/' . $baseSym . '.csv');
        $dates = [];

        if (!file_exists($path)) {
            // create with header
            file_put_contents($path, "date,open,high,low,close,volume\n");
            return $dates;
        }

        $h = fopen($path, 'r');
        if (!$h) return $dates;

        $lineNo = 0;
        while (($line = fgets($h)) !== false) {
            $lineNo++;
            if ($lineNo === 1) continue; // skip header
            $line = trim($line);
            if ($line === '') continue;
            $parts = str_getcsv($line);
            if (!empty($parts[0])) $dates[$parts[0]] = true; // YYYY-MM-DD
        }
        fclose($h);

        return $dates;
    }

    private function csvLine(string $ymd, ?float $open, ?float $high, ?float $low, ?float $close, ?int $volume): string
    {
        // keep plain numbers; null => empty
        $toNum = fn($v) => $v === null ? '' : (string)$v;
        return implode(',', [
                $ymd,
                $toNum($open),
                $toNum($high),
                $toNum($low),
                $toNum($close),
                $toNum($volume),
            ]) . "\n";
    }

    /** Append lines; keeps header if file didn’t exist */
    private function appendCsvLines(string $csvDir, string $baseSym, array $lines): void
    {
        $path = base_path($csvDir . '/' . $baseSym . '.csv');
        // Ensure file exists with header
        if (!file_exists($path)) {
            file_put_contents($path, "date,open,high,low,close,volume\n");
        }
        $h = fopen($path, 'a');
        if ($h) {
            foreach ($lines as $ln) fwrite($h, $ln);
            fclose($h);
        }
    }
}
