<?php

namespace App\Console\Commands;

use App\Services\AssetProfileUpdater;
use App\Services\CsvBars;
use App\Services\CsvUtilities;
use App\Services\DbBars;
use App\Services\StockbitExodusClient;
use App\Support\BrokerSummaryTransformer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScrapeStockbit extends Command
{
    protected $signature = 'stockbit:scrape
        {tickers?* : One or more tickers, e.g. INCO ANTM BRIS}
        {--from= : YYYY-MM-DD (default: 7 days ago)}
        {--to=   : YYYY-MM-DD (default: today)}
        {--transaction_type= : TRANSACTION_TYPE_NET|TRANSACTION_TYPE_BUY|TRANSACTION_TYPE_SELL}
        {--market_board=     : MARKET_BOARD_REGULER|MARKET_BOARD_TUNAI|...}
        {--investor_type=    : INVESTOR_TYPE_ALL|...}
        {--limit= : Max rows per API response (defaults to config(stockbit.defaults.limit))}
        {--no-csv : Do not write CSV (only JSON)}
        {--no-persist : Skip persisting EOD OHLCV data to CSV/DB}
        {--no-profile-sync : Skip syncing asset profiles}
        {--historical-period= : Historical summary period (default: config(stockbit.historical.period))}
        {--historical-limit= : Historical summary limit override}
        {--historical-page= : Historical summary page override}
        {--eod : Capture EOD watchlist snapshot}
        {--watchlist-id= : Override watchlist ID for --eod snapshot}';

    protected $description = 'Scrape Stockbit and persist to DB and Seeds data.';

    /**
     * @var array<string, int>
     */
    private array $assetIds = [];

    public function handle(StockbitExodusClient $api, AssetProfileUpdater $profileUpdater): int
    {
        $from = $this->option('from') ?? now()->subDays(14)->toDateString();
        $to   = $this->option('to')   ?? now()->toDateString();

        $disk = (string) config('stockbit.save_disk');
        $jsonDir = trim((string) config('stockbit.save_dir'), '/');
        $historicalDir = trim('historical_summary', '/');
        $csvDir  = 'broker_summary_csv';

        $historicalDefaults = config('stockbit.historical', []);
        $historicalPeriod = $this->option('historical-period') ?: ($historicalDefaults['period'] ?? 'HS_PERIOD_DAILY');

        $historicalLimitOption = $this->option('historical-limit');
        $historicalLimit = $historicalLimitOption !== null && $historicalLimitOption !== ''
            ? (int) $historicalLimitOption
            : null;
        if ($historicalLimit === null && !empty($historicalDefaults['limit'])) {
            $historicalLimit = (int) $historicalDefaults['limit'];
        }

        $historicalPageOption = $this->option('historical-page');
        $historicalPage = $historicalPageOption !== null && $historicalPageOption !== ''
            ? (int) $historicalPageOption
            : null;
        if ($historicalPage === null && !empty($historicalDefaults['page'])) {
            $historicalPage = (int) $historicalDefaults['page'];
        }

        $exp = StockbitExodusClient::jwtExpiresAt(config('stockbit.bearer'));
        if ($exp && $exp < new \DateTimeImmutable('now')) {
            $this->warn('Your STOCKBIT_BEARER seems expired. Replace it in .env.');
        } elseif ($exp) {
            $this->line('JWT expires at: ' . $exp->format('Y-m-d H:i:s T'));
        }

        $tickers = $this->argument('tickers') ?? [];

        foreach ($tickers as $symbol) {
            $this->info("Fetching {$symbol} {$from} → {$to}");

            $limitOption = $this->option('limit');
            $limit = $this->input->hasParameterOption('--limit')
                ? ($limitOption !== null && $limitOption !== '' ? (int) $limitOption : null)
                : null;

            $json = $api->marketDetectors(
                $symbol,
                $from,
                $to,
                $this->option('transaction_type') ?: null,
                $this->option('market_board') ?: null,
                $this->option('investor_type') ?: null,
                $limit,
            );

            if (isset($json['error'])) {
                $this->error("Error for {$symbol}: {$json['error']} — {$json['message']}");
                continue;
            }

            if (!$this->option('no-profile-sync')) {
                $profileResponse = $api->tickerProfile($symbol);
                $profileResult = $profileUpdater->applyTickerProfileResponse($symbol, $profileResponse);
                if (!$profileResult['ok']) {
                    $message = $profileResult['message'] ?? 'Unknown error';
                    $this->warn("Profile sync failed for {$symbol}: {$profileResult['error']} — {$message}");
                } else {
                    $syncedAt = optional($profileResult['asset']->profile_synced_at)->toDateTimeString();
                    $this->line('Profile synced for ' . $symbol . ($syncedAt ? " at {$syncedAt}" : ''));
                }
            } else {
                $this->line('Profile sync skipped for ' . $symbol);
            }

            $historical = $api->historicalSummary(
                $symbol,
                $historicalPeriod,
                $from,
                $to,
                $historicalLimit,
                $historicalPage,
            );

            $jsonName = sprintf(
                '%s_%s_%s_%s.json',
                $symbol,
                $from,
                $to,
                ($this->option('transaction_type') ?: config('stockbit.defaults.transaction_type'))
            );
            $jsonPath = "{$jsonDir}/{$jsonName}";

            Storage::disk($disk)->put($jsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Saved JSON: ' . ($disk === 'local' ? storage_path("app/{$jsonPath}") : $jsonPath));

            if (isset($historical['error'])) {
                $code = $historical['error'] ?? 'error';
                $message = $historical['message'] ?? 'Unknown error';
                $this->warn("Historical summary error for {$symbol}: {$code} — {$message}");
            } else {
                $historicalNameParts = [$symbol, $from, $to, $historicalPeriod];
                if ($historicalPage !== null) {
                    $historicalNameParts[] = 'page' . $historicalPage;
                }
                $historicalName = implode('_', $historicalNameParts) . '.json';
                $historicalPath = "{$historicalDir}/{$historicalName}";

                Storage::disk($disk)->put($historicalPath, json_encode($historical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line('Saved historical JSON: ' . ($disk === 'local' ? storage_path("app/{$historicalPath}") : $historicalPath));
            }

            if (is_array($json)) {
                $keys = array_keys($json);
                $this->line('Top-level keys: ' . implode(', ', array_slice($keys, 0, 8)) . (count($keys) > 8 ? '…' : ''));
                foreach (['data', 'items', 'result'] as $candidateKey) {
                    if (isset($json[$candidateKey]) && is_array($json[$candidateKey])) {
                        $this->line(ucfirst($candidateKey) . ' rows: ' . count($json[$candidateKey]));
                        break;
                    }
                }
            }

            if (!$this->option('no-csv')) {
                $rows = BrokerSummaryTransformer::toRows($symbol, $json);

                $csvName = sprintf('%s_%s_%s.csv', $symbol, $from, $to);
                $csvPath = "{$csvDir}/{$csvName}";

                $columns = ['symbol', 'date', 'broker', 'net_value', 'buy_value', 'sell_value'];
                $contents = CsvUtilities::rowsToCsv($rows, $columns);

                Storage::disk($disk)->put($csvPath, $contents);

                $this->line('Saved CSV: ' . ($disk === 'local' ? storage_path("app/{$csvPath}") : $csvPath));
                $this->line('CSV rows: ' . count($rows));
            }

            usleep(200_000);
        }

        if ($this->option('eod')) {
            $this->captureWatchlistSnapshot($api, $disk);
        }

        return self::SUCCESS;
    }

    private function captureWatchlistSnapshot(StockbitExodusClient $api, string $disk): void
    {
        $watchlistId = $this->option('watchlist-id') ?: config('stockbit.watchlist.id');

        if (!$watchlistId) {
            $this->warn('--eod requires a watchlist ID (configure stockbit.watchlist.id or pass --watchlist-id).');
            return;
        }

        $query = config('stockbit.watchlist.query', []);

        $watchlist = $api->watchlist($watchlistId, $query);

        if (isset($watchlist['error'])) {
            $code = $watchlist['error'] ?? 'error';
            $message = $watchlist['message'] ?? 'Unknown error';
            $this->error("Watchlist {$watchlistId} error: {$code} — {$message}");
            return;
        }

        $columnLookups = [];
        $headerCustom = $watchlist['data']['header_custom'] ?? [];

        foreach ($headerCustom as $customColumn) {
            $itemId = $customColumn['item_id'] ?? null;

            if ($itemId === null || $itemId === '') {
                continue;
            }

            $column = $api->watchlistColumn($watchlistId, $itemId);

            if (isset($column['error'])) {
                $code = $column['error'] ?? 'error';
                $message = $column['message'] ?? 'Unknown error';
                $this->warn("Watchlist column {$itemId} error: {$code} — {$message}");
                continue;
            }

            $results = $column['data']['results'] ?? [];
            foreach ($results as $row) {
                if (!isset($row['symbol'])) {
                    continue;
                }

                $symbol = $row['symbol'];
                $columnLookups[$symbol][$itemId] = $row['value'] ?? null;
            }

            $columnLookups['__meta'][$itemId] = [
                'item_id' => $itemId,
                'item_name' => $column['data']['item_name'] ?? ($customColumn['value'] ?? null),
            ];
        }

        if (!empty($columnLookups)) {
            if (isset($columnLookups['__meta'])) {
                $watchlist['column_metadata'] = $columnLookups['__meta'];
                unset($columnLookups['__meta']);
            }

            if (isset($watchlist['data']['result']) && is_array($watchlist['data']['result'])) {
                foreach ($watchlist['data']['result'] as &$row) {
                    $symbol = $row['symbol'] ?? null;
                    if ($symbol !== null && isset($columnLookups[$symbol])) {
                        $row['column'] = $columnLookups[$symbol];
                    }
                }
                unset($row);
            }
        }

        $watchlist['meta'] = [
            'watchlist_id' => $watchlistId,
            'fetched_at' => now()->toIso8601String(),
            'query' => $query,
        ];

        $timestamp = now()->format('Y-m-d_H-i-s');
        $watchlistDir = 'watchlist_eod';
        $filename = sprintf('%s_%s.json', $watchlistId, $timestamp);
        $path = "{$watchlistDir}/{$filename}";

        Storage::disk($disk)->put($path, json_encode($watchlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('Saved watchlist JSON: ' . ($disk === 'local' ? storage_path("app/{$path}") : $path));

        if ($this->option('no-persist')) {
            $this->line('Skipping watchlist persistence (--no-persist).');
            return;
        }

        $this->persistWatchlistBars($watchlist);
    }

    private function persistWatchlistBars(array $watchlist): void
    {
        $results = $watchlist['data']['result'] ?? [];

        if (!is_array($results) || empty($results)) {
            return;
        }

        $meta = $watchlist['meta'] ?? [];
        $columnMetadata = $watchlist['column_metadata'] ?? [];

        $fetchedAt = $meta['fetched_at'] ?? null;

        try {
            $date = $fetchedAt ? Carbon::parse($fetchedAt) : now();
        } catch (\Throwable $exception) {
            $date = now();
        }

        $ymd = $date->format('Y-m-d');
        $dbBars = new DbBars(500, false);

        foreach ($results as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            $ohlcv = $this->resolveOhlcvValues($row['column'] ?? [], $columnMetadata, $row);

            if ($ohlcv === null) {
                $this->warn("Incomplete OHLCV data for {$symbol}, skipping update.");
                continue;
            }

            $assetId = $this->getOrCreateAssetId($symbol);

            if ($assetId === null) {
                $this->warn("Unable to resolve asset ID for {$symbol}, skipping DB update.");
            } else {
                $dbBars->add([
                    'asset_id' => $assetId,
                    'date' => $ymd,
                    'open' => $ohlcv['open'],
                    'high' => $ohlcv['high'],
                    'low' => $ohlcv['low'],
                    'close' => $ohlcv['close'],
                    'volume' => $ohlcv['volume'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $csvPath = database_path('seeders/data/historical/' . $symbol . '.csv');

            if (!File::exists($csvPath)) {
                $this->warn("Historical CSV missing for {$symbol}, skipping CSV update.");
                continue;
            }

            $rows = CsvBars::read($csvPath);
            $rows[$ymd] = [
                'date' => $ymd,
                'open' => $this->formatPrice($ohlcv['open']),
                'high' => $this->formatPrice($ohlcv['high']),
                'low' => $this->formatPrice($ohlcv['low']),
                'close' => $this->formatPrice($ohlcv['close']),
                'volume' => $ohlcv['volume'],
            ];

            CsvBars::write($csvPath, $rows);
        }

        $dbBars->flush();
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, array<string, mixed>> $columnMetadata
     * @param array<string, mixed> $row
     * @return array{open: float, high: float, low: float, close: float, volume: int}|null
     */
    private function resolveOhlcvValues(array $columns, array $columnMetadata, array $row): ?array
    {
        $fields = [
            'open' => ['open price', 'open'],
            'high' => ['high price', 'high'],
            'low' => ['low price', 'low'],
            'close' => ['close price', 'close'],
            'volume' => ['volume'],
        ];

        $resolved = [];

        foreach ($fields as $field => $aliases) {
            $raw = $this->resolveColumnValue($columns, $columnMetadata, $row, $aliases);

            if ($raw === null) {
                return null;
            }

            if ($field === 'volume') {
                $value = $this->parseVolume($raw);
                if ($value === null) {
                    return null;
                }
                $resolved['volume'] = $value;
            } else {
                $value = $this->parsePrice($raw);
                if ($value === null) {
                    return null;
                }
                $resolved[$field] = $value;
            }
        }

        return [
            'open' => $resolved['open'],
            'high' => $resolved['high'],
            'low' => $resolved['low'],
            'close' => $resolved['close'],
            'volume' => $resolved['volume'],
        ];
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, array<string, mixed>> $columnMetadata
     * @param array<string, mixed> $row
     * @param array<int, string> $aliases
     */
    private function resolveColumnValue(array $columns, array $columnMetadata, array $row, array $aliases): mixed
    {
        foreach ($columns as $itemId => $value) {
            $metadata = $columnMetadata[$itemId] ?? [];
            $itemName = strtolower((string) ($metadata['item_name'] ?? ''));

            foreach ($aliases as $alias) {
                $needle = strtolower($alias);
                if ($needle !== '' && str_contains($itemName, $needle)) {
                    return $value;
                }
            }
        }

        foreach ($aliases as $alias) {
            $key = str_replace(' ', '_', $alias);
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }

            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $alias))));
            if (array_key_exists($camelKey, $row)) {
                return $row[$camelKey];
            }
        }

        return null;
    }

    private function parsePrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $parsed = CsvUtilities::num(['value' => $value], 'value');

        return $parsed === null ? null : (float) $parsed;
    }

    private function parseVolume(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        if (!is_string($value)) {
            return null;
        }

        $parsed = CsvUtilities::vol(['value' => $value], 'value');

        return $parsed === null ? null : (int) $parsed;
    }

    private function formatPrice(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function getOrCreateAssetId(string $symbol): ?int
    {
        if (isset($this->assetIds[$symbol])) {
            return $this->assetIds[$symbol];
        }

        $id = DB::table('assets')->where('symbol', $symbol)->value('id');

        if ($id) {
            return $this->assetIds[$symbol] = (int) $id;
        }

        try {
            $inserted = DB::table('assets')->insertGetId([
                'symbol' => $symbol,
                'name' => $symbol,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->warn("Failed to create asset for {$symbol}: {$exception->getMessage()}");
            return null;
        }

        return $this->assetIds[$symbol] = (int) $inserted;
    }
}
