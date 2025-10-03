<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Services\AssetProfileUpdater;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AssetProfileJsonSeeder extends Seeder
{
    /**
     * Directory where profile JSON files are stored.
     */
    private string $profileDir;

    /**
     * Columns that should be treated as JSON payloads when seeding.
     */
    private array $jsonColumns = [
        'address',
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
        'ticker_profile_payload',
    ];

    public function __construct(private AssetProfileUpdater $profileUpdater)
    {
        $this->profileDir = database_path('seeders/data/profiles');
    }

    public function run(): void
    {
        if (!is_dir($this->profileDir)) {
            $this->command?->warn('Profile JSON dir not found: ' . $this->profileDir);
            return;
        }

        $files = collect(File::files($this->profileDir))
            ->filter(fn($file) => Str::lower($file->getExtension()) === 'json')
            ->sortBy(fn($file) => $file->getFilename())
            ->values();

        if ($files->isEmpty()) {
            $this->command?->warn('No profile JSON files found in ' . $this->profileDir);
            return;
        }

        foreach ($files as $file) {
            $this->seedProfileFromJson($file->getPathname());
        }
    }

    private function seedProfileFromJson(string $path): void
    {
        $payload = $this->readJson($path);
        if ($payload === null) {
            $this->command?->warn('Profile JSON is empty or invalid: ' . $path);
            return;
        }
        $symbol = $this->resolveSymbolFromPayload($payload, $path);

        $asset = Asset::firstOrCreate(
            ['symbol' => $symbol],
            ['name' => $payload['name'] ?? $symbol]
        );

        $updates = $this->normalizePayload($payload);

        if (!isset($updates['name']) && empty($asset->name)) {
            $updates['name'] = $symbol;
        }

        if (!isset($updates['profile_synced_at'])) {
            $updates['profile_synced_at'] = Carbon::now();
        }

        if (isset($updates['ticker_profile_payload'])) {
            $this->profileUpdater->applyTickerProfileResponse($asset, ['data' => $updates['ticker_profile_payload']]);
            unset($updates['ticker_profile_payload']);
            $asset = $asset->fresh();
        }

        if (!empty($updates)) {
            $asset->fill($updates);
            $asset->save();
        }

        $this->command?->info('Seeded profile for ' . $symbol);
    }

    private function resolveSymbolFromPayload(array $payload, string $path): string
    {
        if (!empty($payload['symbol'])) {
            return Str::upper(trim((string) $payload['symbol']));
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $filename = Str::beforeLast($filename, '_profile');

        return Str::upper($filename);
    }

    private function normalizePayload(array $payload): array
    {
        $updates = [];

        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $key = Str::snake($key);

            if ($key === 'symbol') {
                continue;
            }

            if (in_array($key, $this->jsonColumns, true)) {
                $updates[$key] = $value;
                continue;
            }

            if (in_array($key, ['lot_size'], true)) {
                if (is_numeric($value)) {
                    $updates[$key] = (int) $value;
                }
                continue;
            }

            if (in_array($key, ['tick_size', 'float', 'ipo_price', 'marketcap'], true)) {
                if (is_numeric($value)) {
                    $updates[$key] = (float) $value;
                    continue;
                }

                $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);
                if ($normalized === null) {
                    continue;
                }

                $normalized = trim($normalized);
                if ($normalized === '' || $normalized === '-') {
                    continue;
                }

                if (is_numeric($normalized)) {
                    $updates[$key] = (float) $normalized;
                }
                continue;
            }

            if (in_array($key, ['ipo_date', 'profile_synced_at'], true)) {
                $parsed = Carbon::make($value);
                if ($parsed) {
                    $updates[$key] = $parsed;
                }
                continue;
            }

            $updates[$key] = $value;
        }

        return $updates;
    }

    private function readJson(string $path): ?array
    {
        if (!File::exists($path)) {
            return null;
        }

        $contents = File::get($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}

