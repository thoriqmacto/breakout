<?php

namespace App\Services;

use App\Models\Asset;
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

            $rows = BrokerSummaryTransformer::toRows($symbol, $decoded);
            if ($rows === []) {
                continue;
            }

            $asset = Asset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);
            if (!$asset->sync_broker_summary) {
                continue;
            }
            $timestamp = now();
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'asset_id' => $asset->id,
                    'date' => $row['date'],
                    'broker' => $row['broker'],
                    'net_value' => $row['net_value'],
                    'buy_value' => $row['buy_value'],
                    'sell_value' => $row['sell_value'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($payload, 500) as $chunk) {
                Broksum::upsert(
                    $chunk,
                    ['asset_id', 'date', 'broker'],
                    ['net_value', 'buy_value', 'sell_value', 'updated_at']
                );
            }

            $rowCount += count($payload);
            $symbols[$symbol] = ($symbols[$symbol] ?? 0) + count($payload);
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
}
