<?php

namespace App\Console\Commands;

use App\Services\StockbitExodusClient;
use App\Support\BrokerSummaryTransformer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ScrapeBrokerSummary extends Command
{
    protected $signature = 'stockbit:scrape
        {tickers* : One or more tickers, e.g. INCO ANTM BRIS}
        {--from= : YYYY-MM-DD (default: 7 days ago)}
        {--to=   : YYYY-MM-DD (default: today)}
        {--transaction_type= : TRANSACTION_TYPE_NET|TRANSACTION_TYPE_BUY|TRANSACTION_TYPE_SELL}
        {--market_board=     : MARKET_BOARD_REGULER|MARKET_BOARD_TUNAI|...}
        {--investor_type=    : INVESTOR_TYPE_ALL|...}
        {--limit=25 : Max rows per API response}
        {--no-csv : Do not write CSV (only JSON)}';

    protected $description = 'Scrape Stockbit Exodus marketdetectors data and optionally emit a CSV summary';

    public function handle(StockbitExodusClient $api): int
    {
        $from = $this->option('from') ?? now()->subDays(7)->toDateString();
        $to   = $this->option('to')   ?? now()->toDateString();

        $disk = (string) config('stockbit.save_disk');
        $jsonDir = trim((string) config('stockbit.save_dir'), '/');
        $csvDir  = 'broker_summary_csv';

        $exp = StockbitExodusClient::jwtExpiresAt(config('stockbit.bearer'));
        if ($exp && $exp < new \DateTimeImmutable('now')) {
            $this->warn('Your STOCKBIT_BEARER seems expired. Replace it in .env.');
        } elseif ($exp) {
            $this->line('JWT expires at: ' . $exp->format('Y-m-d H:i:s T'));
        }

        foreach ($this->argument('tickers') as $symbol) {
            $this->info("Fetching {$symbol} {$from} → {$to}");

            $json = $api->marketDetectors(
                $symbol,
                $from,
                $to,
                $this->option('transaction_type') ?: null,
                $this->option('market_board') ?: null,
                $this->option('investor_type') ?: null,
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );

            if (isset($json['error'])) {
                $this->error("Error for {$symbol}: {$json['error']} — {$json['message']}");
                continue;
            }

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

                $stream = fopen('php://temp', 'w+');
                fputcsv($stream, ['symbol', 'date', 'broker', 'net_value', 'buy_value', 'sell_value']);
                foreach ($rows as $row) {
                    fputcsv($stream, [
                        $row['symbol'],
                        $row['date'],
                        $row['broker'],
                        $row['net_value'],
                        $row['buy_value'],
                        $row['sell_value'],
                    ]);
                }

                rewind($stream);
                $contents = stream_get_contents($stream) ?: '';
                fclose($stream);

                Storage::disk($disk)->put($csvPath, $contents);

                $this->line('Saved CSV: ' . ($disk === 'local' ? storage_path("app/{$csvPath}") : $csvPath));
                $this->line('CSV rows: ' . count($rows));
            }

            usleep(200_000);
        }

        return self::SUCCESS;
    }
}
