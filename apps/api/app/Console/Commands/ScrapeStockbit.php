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
use Illuminate\Support\Str;

class ScrapeStockbit extends Command
{
    protected $signature = 'stockbit:scrape
        {tickers?* : One or more tickers, e.g. INCO ANTM BRIS}
        {--all : Process all tickers configured in csv.index_symbols}
        {--market-detector : Fetch market detector data for the provided tickers}
        {--from= : YYYY-MM-DD (default: 14 days ago)}
        {--to=   : YYYY-MM-DD (default: today)}
        {--no-persist : Skip persisting EOD OHLCV data to CSV/DB}
        {--no-profile-sync : Skip syncing asset profiles}
        {--eod : Capture EOD watchlist snapshot}
        {--watchlist-id= : Override watchlist ID for --eod snapshot}
        {--overwrite : Force refreshing the --eod watchlist snapshot JSON}';

    protected $description = 'Scrape Stockbit and persist to DB and Seeds data.';

    /**
     * @var array<string, int>
     */
    private array $assetIds = [];

    public function handle(StockbitExodusClient $api, AssetProfileUpdater $profileUpdater): int
    {
        $fetchMarketDetector = (bool) $this->option('market-detector');
        $from = $fetchMarketDetector
            ? ($this->option('from') ?? now()->subDays(14)->toDateString())
            : null;
        $to = $fetchMarketDetector
            ? ($this->option('to') ?? now()->toDateString())
            : null;

        $disk = (string) config('stockbit.save_disk');
        $jsonDir = trim((string) config('stockbit.save_dir'), '/');
        $historicalDir = trim('historical_summary', '/');
        $csvDir  = 'broker_summary_csv';

        $defaults = config('stockbit.defaults', []);
        $transactionType = is_string($defaults['transaction_type'] ?? null)
            ? $defaults['transaction_type']
            : null;
        $marketBoard = is_string($defaults['market_board'] ?? null)
            ? $defaults['market_board']
            : null;
        $investorType = is_string($defaults['investor_type'] ?? null)
            ? $defaults['investor_type']
            : null;
        $limitDefault = $defaults['limit'] ?? null;
        $limit = is_numeric($limitDefault) ? (int) $limitDefault : null;
        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }

        $historicalDefaults = config('stockbit.historical', []);
        $historicalPeriod = is_string($historicalDefaults['period'] ?? null)
            ? $historicalDefaults['period']
            : 'HS_PERIOD_DAILY';
        $historicalLimitDefault = $historicalDefaults['limit'] ?? null;
        $historicalLimit = is_numeric($historicalLimitDefault) ? (int) $historicalLimitDefault : null;
        if ($historicalLimit !== null && $historicalLimit <= 0) {
            $historicalLimit = null;
        }
        $historicalPageDefault = $historicalDefaults['page'] ?? null;
        $historicalPage = is_numeric($historicalPageDefault) ? (int) $historicalPageDefault : null;
        if ($historicalPage !== null && $historicalPage <= 0) {
            $historicalPage = null;
        }

        $bearer = config('stockbit.bearer');
        $exp = StockbitExodusClient::jwtExpiresAt($bearer);
        if ($exp && $exp < new \DateTimeImmutable('now')) {
            $this->warn('Your STOCKBIT_BEARER seems expired.');

            $newBearer = trim((string) $this->ask('Please enter a new bearer token (leave blank to cancel)'));
            if ($newBearer === '') {
                $this->error('No bearer token supplied. Exiting.');

                return self::FAILURE;
            }

            config(['stockbit.bearer' => $newBearer]);
            $api->setBearer($newBearer);

            $exp = StockbitExodusClient::jwtExpiresAt($newBearer);
            if ($exp) {
                $this->line('New JWT expires at: ' . $exp->format('Y-m-d H:i:s T'));
            }
        } elseif ($exp) {
            $this->line('JWT expires at: ' . $exp->format('Y-m-d H:i:s T'));
        }

        $argumentTickers = array_filter(
            $this->argument('tickers') ?? [],
            static fn ($symbol) => is_string($symbol) && $symbol !== ''
        );

        $configuredTickers = [];

        if ($this->option('all')) {
            $configuredTickers = config('csv.index_symbols', []);

            if (!is_array($configuredTickers)) {
                $this->warn('The csv.index_symbols configuration is missing or invalid.');
                $configuredTickers = [];
            }

            $configuredTickers = array_filter(
                $configuredTickers,
                static fn ($symbol) => is_string($symbol) && $symbol !== ''
            );
        }

        $tickers = array_values(array_unique(array_merge($argumentTickers, $configuredTickers)));
        $tickers = array_map(static fn (string $symbol): string => Str::upper($symbol), $tickers);

        foreach ($tickers as $symbol) {
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

            if (!$fetchMarketDetector) {
                continue;
            }

            $fromString = (string) $from;
            $toString = (string) $to;

            $this->info("Fetching {$symbol} {$fromString} → {$toString}");

            $json = $api->marketDetectors(
                $symbol,
                $fromString,
                $toString,
                $transactionType,
                $marketBoard,
                $investorType,
                $limit,
            );

            if (isset($json['error'])) {
                $this->error("Error for {$symbol}: {$json['error']} — {$json['message']}");
                continue;
            }

            $historical = $api->historicalSummary(
                $symbol,
                $historicalPeriod,
                $fromString,
                $toString,
                $historicalLimit,
                $historicalPage,
            );

            $jsonName = sprintf(
                '%s_%s_%s_%s.json',
                $symbol,
                $fromString,
                $toString,
                $transactionType ?? 'default'
            );
            $jsonPath = "{$jsonDir}/{$jsonName}";

            Storage::disk($disk)->put($jsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Saved JSON: ' . ($disk === 'local' ? storage_path("app/{$jsonPath}") : $jsonPath));

            if (isset($historical['error'])) {
                $code = $historical['error'] ?? 'error';
                $message = $historical['message'] ?? 'Unknown error';
                $this->warn("Historical summary error for {$symbol}: {$code} — {$message}");
            } else {
                $historicalNameParts = [$symbol, $fromString, $toString, $historicalPeriod];
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

            $rows = BrokerSummaryTransformer::toRows($symbol, $json);

            $csvName = sprintf('%s_%s_%s.csv', $symbol, $fromString, $toString);
            $csvPath = "{$csvDir}/{$csvName}";

            $columns = ['symbol', 'date', 'broker', 'net_value', 'buy_value', 'sell_value'];
            $contents = CsvUtilities::rowsToCsv($rows, $columns);

            Storage::disk($disk)->put($csvPath, $contents);

            $this->line('Saved CSV: ' . ($disk === 'local' ? storage_path("app/{$csvPath}") : $csvPath));
            $this->line('CSV rows: ' . count($rows));

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
        $now = now();
        $date = $now->format('Y-m-d');
        $watchlistDir = 'watchlist_eod';
        $filename = sprintf('%s_%s.json', $watchlistId, $date);
        $path = "{$watchlistDir}/{$filename}";
        $overwrite = (bool) $this->option('overwrite');

        $watchlist = null;

        if (!$overwrite) {
            $watchlist = $this->loadWatchlistSnapshotFromDisk($disk, $path);
        }

        if ($watchlist === null) {
            $watchlist = $this->fetchWatchlistSnapshot($api, $watchlistId, $query);

            if ($watchlist === null) {
                return;
            }

            $watchlist['meta'] = [
                'watchlist_id' => $watchlistId,
                'fetched_at' => $now->toIso8601String(),
                'query' => $query,
            ];

            Storage::disk($disk)->put($path, json_encode($watchlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Saved watchlist JSON: ' . ($disk === 'local' ? storage_path("app/{$path}") : $path));
        } else {
            $this->line('Loaded watchlist JSON: ' . ($disk === 'local' ? storage_path("app/{$path}") : $path));

            $existingMeta = $watchlist['meta'] ?? [];
            if (!is_array($existingMeta)) {
                $existingMeta = [];
            }

            $watchlist['meta'] = array_replace(
                [
                    'watchlist_id' => $watchlistId,
                    'query' => $query,
                ],
                $existingMeta
            );

            if (!isset($watchlist['meta']['fetched_at'])) {
                $watchlist['meta']['fetched_at'] = Carbon::createFromFormat('Y-m-d', $date, $now->getTimezone())
                    ->startOfDay()
                    ->toIso8601String();
            }
        }

        if ($this->option('no-persist')) {
            $this->line('Skipping watchlist persistence (--no-persist).');
            return;
        }

        $this->persistWatchlistBars($watchlist);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function fetchWatchlistSnapshot(StockbitExodusClient $api, string $watchlistId, array $query): ?array
    {
        $watchlist = $api->watchlist($watchlistId, $query);

        if (isset($watchlist['error'])) {
            $code = $watchlist['error'] ?? 'error';
            $message = $watchlist['message'] ?? 'Unknown error';
            $this->error("Watchlist {$watchlistId} error: {$code} — {$message}");
            return null;
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

        return $watchlist;
    }

    private function loadWatchlistSnapshotFromDisk(string $disk, string $path): ?array
    {
        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        try {
            $contents = Storage::disk($disk)->get($path);
        } catch (\Throwable $exception) {
            $this->warn('Failed to read existing watchlist snapshot: ' . $exception->getMessage());
            return null;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            $this->warn('Existing watchlist snapshot is invalid JSON.');
            return null;
        }

        return $decoded;
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

        $allowedSymbols = $this->getAllowedWatchlistSymbols();

        foreach ($results as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            if ($allowedSymbols !== [] && !isset($allowedSymbols[$symbol])) {
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
                'open' => $this->formatPrice($ohlcv['open'],0),
                'high' => $this->formatPrice($ohlcv['high'],0),
                'low' => $this->formatPrice($ohlcv['low'],0),
                'close' => $this->formatPrice($ohlcv['close'],0),
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
            'close' => ['close price', 'close', 'last', 'last price'],
            'volume' => ['volume', 'total volume', 'vol'],
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
        $normalize = static function (?string $value): ?string {
            if ($value === null) {
                return null;
            }

            $value = strtolower(trim($value));

            if ($value === '') {
                return null;
            }

            $value = preg_replace('/[^a-z0-9]+/', '', $value);

            return $value === '' ? null : $value;
        };

        $aliasNeedles = [];
        $candidateKeys = [];

        foreach ($aliases as $alias) {
            if (!is_string($alias) || trim($alias) === '') {
                continue;
            }

            $aliasNeedle = $normalize($alias);
            if ($aliasNeedle !== null) {
                $aliasNeedles[] = $aliasNeedle;
            }

            $candidateKeys[] = $alias;
            $candidateKeys[] = strtolower($alias);
            $candidateKeys[] = Str::snake($alias);
            $candidateKeys[] = Str::camel($alias);
        }

        $aliasNeedles = array_values(array_unique($aliasNeedles));
        $candidateKeys = array_values(array_unique(array_filter($candidateKeys, static fn ($value) => is_string($value) && $value !== '')));

        foreach ($columns as $itemId => $value) {
            $metadata = $columnMetadata[$itemId] ?? [];
            $names = [
                $metadata['item_name'] ?? null,
                $metadata['value'] ?? null,
            ];

            foreach ($names as $name) {
                $normalizedName = $normalize(is_string($name) ? $name : null);

                if ($normalizedName === null) {
                    continue;
                }

                foreach ($aliasNeedles as $needle) {
                    if ($needle !== '' && str_contains($normalizedName, $needle)) {
                        return $value;
                    }
                }
            }
        }

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        $normalizedRow = [];

        foreach ($row as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalizedKey = $normalize((string) $key);

            if ($normalizedKey !== null && !array_key_exists($normalizedKey, $normalizedRow)) {
                $normalizedRow[$normalizedKey] = $value;
            }
        }

        foreach ($aliasNeedles as $needle) {
            if ($needle === '' || !array_key_exists($needle, $normalizedRow)) {
                continue;
            }

            return $normalizedRow[$needle];
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

    private function formatPrice(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
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
            ]);
        } catch (\Throwable $exception) {
            $this->warn("Failed to create asset for {$symbol}: {$exception->getMessage()}");
            return null;
        }

        return $this->assetIds[$symbol] = (int) $inserted;
    }

    /**
     * @return array<string, true>
     */
    private function getAllowedWatchlistSymbols(): array
    {
        static $allowed = null;

        if ($allowed !== null) {
            return $allowed;
        }

        $configured = config('csv.index_symbols', []);

        if (!is_array($configured)) {
            $configured = [];
        }

        $normalized = [];

        foreach ($configured as $symbol) {
            if (!is_string($symbol)) {
                continue;
            }

            $normalizedSymbol = strtoupper(trim($symbol));

            if ($normalizedSymbol === '') {
                continue;
            }

            $normalized[$normalizedSymbol] = true;
        }

        return $allowed = $normalized;
    }
}
