<?php

namespace App\Services;

use App\Models\Asset;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AssetProfileUpdater
{
    public function __construct(private StockbitExodusClient $client) {}

    /**
     * Sync an asset record with the latest ticker profile information from Stockbit.
     */
    public function sync(Asset|string $asset): array
    {
        $model = $asset instanceof Asset ? $asset : $this->resolveAsset($asset);
        $response = $this->client->tickerProfile($model->symbol);

        return $this->applyTickerProfileResponse($model, $response);
    }

    /**
     * Apply a previously fetched ticker profile response to an asset record.
     */
    public function applyTickerProfileResponse(Asset|string $asset, array $response): array
    {
        $model = $asset instanceof Asset ? $asset : $this->resolveAsset($asset);

        if (isset($response['error'])) {
            return [
                'ok' => false,
                'error' => $response['error'],
                'message' => $response['message'] ?? null,
            ];
        }

        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            return [
                'ok' => false,
                'error' => 'invalid_response',
                'message' => 'Ticker profile payload missing data node.',
            ];
        }

        $updates = $this->mapProfileData($data);
        $updates = array_merge($updates, $this->mapHistoryMetrics($data['history'] ?? null));
        $updates['ticker_profile_payload'] = $data;
        $updates['profile_synced_at'] = Carbon::now();

        $model->fill($updates);
        $model->save();

        $this->writeProfileSeederJson($model, $data, $updates);

        return [
            'ok' => true,
            'asset' => $model->fresh(),
            'profile' => $data,
        ];
    }

    public function getIPODate(Asset|string $asset): ?Carbon
    {
        $model = $asset instanceof Asset ? $asset : $this->resolveAsset($asset);
        $metrics = $this->resolveHistoryMetrics($model);

        if (isset($metrics['ipo_date'])) {
            $date = $metrics['ipo_date'];

            return $date instanceof Carbon ? $date : $this->parseHistoryDate($date);
        }

        $fresh = $model->fresh();

        return $fresh?->ipo_date instanceof Carbon ? $fresh->ipo_date : null;
    }

    public function getFreeFloat(Asset|string $asset): ?float
    {
        $model = $asset instanceof Asset ? $asset : $this->resolveAsset($asset);
        $metrics = $this->resolveHistoryMetrics($model);

        if (array_key_exists('float', $metrics)) {
            return $metrics['float'] !== null ? (float) $metrics['float'] : null;
        }

        $fresh = $model->fresh();

        return $fresh?->float;
    }

    private function resolveAsset(Asset|string $asset): Asset
    {
        if ($asset instanceof Asset) {
            return $asset;
        }

        $symbol = strtoupper(trim($asset));

        return Asset::firstOrCreate(
            ['symbol' => $symbol],
            ['name' => $symbol]
        );
    }

    private function mapProfileData(array $data): array
    {
        $keys = [
            'address',
            'background',
            'history',
            'key_executive',
            'secretary',
            'shareholder',
            'subsidiary',
            'profile',
            'fee',
            'asset_allocation',
            'shareholder_reksa',
            'pdf',
            'shareholder_numbers',
            'badges',
            'top_holdings',
            'shareholder_director_commissioner',
            'listing_information',
        ];

        $updates = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $updates[$key] = $data[$key];
            }
        }

        return $updates;
    }

    private function mapHistoryMetrics(?array $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        $updates = [];

        if (isset($history['date'])) {
            $date = $this->parseHistoryDate($history['date']);
            if ($date !== null) {
                $updates['ipo_date'] = $date;
            }
        }

        if (isset($history['price'])) {
            $price = $this->parseNumericValue($history['price']);
            if ($price !== null) {
                $updates['ipo_price'] = $price;
            }
        }

        if (isset($history['free_float'])) {
            $freeFloat = $this->parseNumericValue($history['free_float']);
            if ($freeFloat !== null) {
                $updates['float'] = $freeFloat;
            }
        }

        return $updates;
    }

    private function parseHistoryDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(clone $value)->startOfDay();
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        foreach (['d M Y', 'd F Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $normalized, 'Asia/Jakarta')->startOfDay();
            } catch (\Throwable) {
                // Try next format.
            }
        }

        try {
            return Carbon::parse($normalized, 'Asia/Jakarta')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNumericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        $normalized = str_replace([',', ' '], ['', ''], $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        if ($normalized === '' || $normalized === '-' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function resolveHistoryMetrics(Asset $asset): array
    {
        $history = $this->getHistoryFromSeeder($asset);
        if (is_array($history)) {
            $metrics = $this->mapHistoryMetrics($history);
            $this->persistHistoryMetrics($asset, $metrics);

            return $metrics;
        }

        $response = $this->client->tickerProfile($asset->symbol);
        $result = $this->applyTickerProfileResponse($asset, $response);
        if (! $result['ok']) {
            return [];
        }

        return $this->mapHistoryMetrics($result['profile']['history'] ?? null);
    }

    private function persistHistoryMetrics(Asset $asset, array $metrics): void
    {
        $allowedKeys = ['ipo_date', 'ipo_price', 'float'];
        $updates = [];

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $metrics) && $metrics[$key] !== null) {
                $updates[$key] = $metrics[$key];
            }
        }

        if ($updates === []) {
            return;
        }

        $asset->fill($updates);
        $asset->save();
    }

    private function getHistoryFromSeeder(Asset $asset): ?array
    {
        $profile = $this->readProfileSeederJson($asset);
        if (! is_array($profile)) {
            return null;
        }

        $history = $profile['history'] ?? null;

        return is_array($history) ? $history : null;
    }

    private function readProfileSeederJson(Asset $asset): ?array
    {
        $path = $this->profileSeederPath($asset);

        if (! File::exists($path)) {
            return null;
        }

        try {
            $contents = File::get($path);
        } catch (\Throwable) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function profileSeederPath(Asset $asset): string
    {
        $directory = database_path('seeders/data/profiles');

        return $directory.DIRECTORY_SEPARATOR.Str::upper($asset->symbol).'_profile.json';
    }

    private function writeProfileSeederJson(Asset $asset, array $profile, array $updates): void
    {
        $directory = database_path('seeders/data/profiles');
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $path = $this->profileSeederPath($asset);
        if (File::exists($path)) {
            return;
        }

        $payload = array_merge(
            [
                'symbol' => Str::upper($asset->symbol),
                'name' => $asset->name,
            ],
            $updates
        );

        $payload['profile_synced_at'] = optional($asset->profile_synced_at)->toIso8601String();
        $payload['ticker_profile_payload'] = $profile;

        foreach ($payload as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $payload[$key] = $value->format(DATE_ATOM);
            }
        }

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
