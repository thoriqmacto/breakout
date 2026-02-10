<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryFact;
use App\Models\Broksum;
use App\Support\BrokerSummaryTransformer;
use Illuminate\Support\Facades\Storage;

class BrokerSummaryImporter
{
    /**
     * Import broker summary JSON files from disk into the broksums table.
     *
     * @return array{file_count:int,row_count:int,symbols:array<string,int>}
     */
    public function importFromDisk(?string $disk = null, ?string $directory = null): array
    {
        $disk = $disk ?? (string) config('stockbit.save_disk', 'local');
        $directory = trim($directory ?? (string) config('stockbit.save_dir', 'broker_summary'), '/');

        $fileCount = 0;
        $rowCount = 0;
        $symbols = [];

        try {
            $storage = Storage::disk($disk);
            $paths = array_filter(
                $storage->files($directory),
                static fn (string $path): bool => str_ends_with(strtolower($path), '.json')
            );
        } catch (\Throwable) {
            return [
                'file_count' => 0,
                'row_count' => 0,
                'symbols' => [],
            ];
        }

        foreach ($paths as $path) {
            $fileCount++;
            $symbol = $this->symbolFromPath($path);
            if ($symbol === null) {
                continue;
            }

            $contents = Storage::disk($disk)->get($path);
            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                continue;
            }

            $meta = $this->metaFromPath($path);
            $transactionType = $meta['transaction_type'] ?? config('stockbit.defaults.transaction_type');
            $facts = BrokerSummaryTransformer::toFacts($symbol, $decoded, $transactionType);
            if ($facts === []) {
                continue;
            }

            $asset = Asset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);
            if (!$asset->sync_broker_summary) {
                continue;
            }
            $timestamp = now();
            $factsPayload = [];
            $broksumPayload = [];

            foreach ($facts as $fact) {
                $factsPayload[] = [
                    'asset_id' => $asset->id,
                    'trade_date' => $fact['trade_date'],
                    'broker_code' => $fact['broker_code'],
                    'transaction_type' => $fact['transaction_type'],
                    'broker_type' => $fact['broker_type'],
                    'buy_lot' => $fact['buy_lot'],
                    'buy_volume' => $fact['buy_volume'],
                    'buy_value' => $fact['buy_value'],
                    'buy_value_v' => $fact['buy_value_v'],
                    'buy_avg_price' => $fact['buy_avg_price'],
                    'sell_lot' => $fact['sell_lot'],
                    'sell_volume' => $fact['sell_volume'],
                    'sell_value' => $fact['sell_value'],
                    'sell_value_v' => $fact['sell_value_v'],
                    'sell_avg_price' => $fact['sell_avg_price'],
                    'net_lot' => $fact['net_lot'],
                    'net_volume' => $fact['net_volume'],
                    'net_value' => $fact['net_value'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $broksumPayload[] = [
                    'asset_id' => $asset->id,
                    'date' => $fact['trade_date'],
                    'broker' => $fact['broker_code'],
                    'net_value' => $fact['net_value'],
                    'buy_value' => $fact['buy_value'],
                    'buy_avg_price' => $fact['buy_avg_price'],
                    'sell_value' => $fact['sell_value'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($factsPayload, 500) as $chunk) {
                BrokerSummaryFact::upsert(
                    $chunk,
                    ['asset_id', 'trade_date', 'broker_code', 'transaction_type'],
                    [
                        'broker_type',
                        'buy_lot',
                        'buy_volume',
                        'buy_value',
                        'buy_value_v',
                        'buy_avg_price',
                        'sell_lot',
                        'sell_volume',
                        'sell_value',
                        'sell_value_v',
                        'sell_avg_price',
                        'net_lot',
                        'net_volume',
                        'net_value',
                        'updated_at',
                    ]
                );
            }

            foreach (array_chunk($broksumPayload, 500) as $chunk) {
                Broksum::upsert(
                    $chunk,
                    ['asset_id', 'date', 'broker'],
                    ['net_value', 'buy_value', 'buy_avg_price', 'sell_value', 'updated_at']
                );
            }

            $bandarDetector = BrokerSummaryTransformer::toBandarDetectorSummary(
                $decoded,
                $meta['from_date'] ?? null,
                $meta['to_date'] ?? null,
                $transactionType,
            );
            if ($bandarDetector !== null && $bandarDetector['from_date'] && $bandarDetector['to_date']) {
                $metricsJson = $bandarDetector['metrics_json'];
                if (is_array($metricsJson)) {
                    $metricsJson = json_encode($metricsJson);
                }
                BandarDetectorSummary::upsert(
                    [[
                        'asset_id' => $asset->id,
                        'from_date' => $bandarDetector['from_date'],
                        'to_date' => $bandarDetector['to_date'],
                        'transaction_type' => $bandarDetector['transaction_type'],
                        'broker_accdist' => $bandarDetector['broker_accdist'],
                        'number_broker_buysell' => $bandarDetector['number_broker_buysell'],
                        'total_buyer' => $bandarDetector['total_buyer'],
                        'total_seller' => $bandarDetector['total_seller'],
                        'value' => $bandarDetector['value'],
                        'volume' => $bandarDetector['volume'],
                        'average_price' => $bandarDetector['average_price'],
                        'metrics_json' => $metricsJson,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]],
                    ['asset_id', 'from_date', 'to_date', 'transaction_type'],
                    [
                        'broker_accdist',
                        'number_broker_buysell',
                        'total_buyer',
                        'total_seller',
                        'value',
                        'volume',
                        'average_price',
                        'metrics_json',
                        'updated_at',
                    ]
                );
            }

            $rowCount += count($factsPayload);
            $symbols[$symbol] = ($symbols[$symbol] ?? 0) + count($factsPayload);
        }

        return [
            'file_count' => $fileCount,
            'row_count' => $rowCount,
            'symbols' => $symbols,
        ];
    }

    private function symbolFromPath(string $path): ?string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $parts = explode('_', $filename);
        $symbol = strtoupper((string) ($parts[0] ?? ''));

        return $symbol !== '' ? $symbol : null;
    }

    /**
     * @return array{symbol:?string,from_date:?string,to_date:?string,transaction_type:?string}
     */
    private function metaFromPath(string $path): array
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $parts = explode('_', $filename);

        $symbol = strtoupper((string) ($parts[0] ?? ''));
        $fromDate = $parts[1] ?? null;
        $toDate = $parts[2] ?? null;
        $transactionType = null;

        if (count($parts) > 3) {
            $transactionType = implode('_', array_slice($parts, 3));
        }

        return [
            'symbol' => $symbol !== '' ? $symbol : null,
            'from_date' => is_string($fromDate) && $fromDate !== '' ? $fromDate : null,
            'to_date' => is_string($toDate) && $toDate !== '' ? $toDate : null,
            'transaction_type' => is_string($transactionType) && $transactionType !== '' ? $transactionType : null,
        ];
    }
}
