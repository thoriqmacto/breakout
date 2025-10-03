<?php

namespace App\Console\Commands;

use App\Services\AssetProfileUpdater;
use App\Services\CsvUtilities;
use App\Services\StockbitExodusClient;
use App\Support\BrokerSummaryTransformer;
use Illuminate\Console\Command;
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
        {--no-profile-sync : Skip syncing asset profiles}
        {--historical-period= : Historical summary period (default: config(stockbit.historical.period))}
        {--historical-limit= : Historical summary limit override}
        {--historical-page= : Historical summary page override}
        {--eod : Capture EOD watchlist snapshot}
        {--watchlist-id= : Override watchlist ID for --eod snapshot}';

    protected $description = 'Scrape Stockbit and persist to DB and Seeds data.';

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
    }
}
