<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Services\AssetProfileUpdater;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AssetProfileCsvSeeder extends Seeder
{
    /**
     * Directory where profile CSVs are stored.
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
            $this->command?->warn('Profile CSV dir not found: ' . $this->profileDir);
            return;
        }

        $files = collect(File::files($this->profileDir))
            ->filter(fn($file) => Str::lower($file->getExtension()) === 'csv')
            ->sortBy(fn($file) => $file->getFilename())
            ->values();

        if ($files->isEmpty()) {
            $this->command?->warn('No profile CSV files found in ' . $this->profileDir);
            return;
        }

        foreach ($files as $file) {
            $this->seedProfileFromCsv($file->getPathname());
        }
    }

    private function seedProfileFromCsv(string $path): void
    {
        $row = $this->readFirstRow($path);
        if ($row === null) {
            $this->command?->warn('Profile CSV is empty: ' . $path);
            return;
        }
        $symbol = $this->resolveSymbolFromRow($row, $path);

        $asset = Asset::firstOrCreate(
            ['symbol' => $symbol],
            ['name' => $row['name'] ?? $symbol]
        );

        $updates = $this->normalizeRow($row);

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

    private function resolveSymbolFromRow(array $row, string $path): string
    {
        if (!empty($row['symbol'])) {
            return Str::upper(trim((string) $row['symbol']));
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $filename = Str::beforeLast($filename, '_profile');

        return Str::upper($filename);
    }

    private function normalizeRow(array $row): array
    {
        $updates = [];

        foreach ($row as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $key = Str::snake($key);

            if ($key === 'symbol') {
                continue;
            }

            if (in_array($key, $this->jsonColumns, true)) {
                $decoded = json_decode((string) $value, true);
                $updates[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                continue;
            }

            if (in_array($key, ['lot_size'], true)) {
                $updates[$key] = (int) $value;
                continue;
            }

            if (in_array($key, ['tick_size', 'float', 'ipo_price', 'marketcap'], true)) {
                $updates[$key] = (float) $value;
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

    private function readFirstRow(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $headers = null;
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($headers === null) {
                if (isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                }
                $headers = array_map(fn($value) => Str::of($value)->lower()->trim()->value(), $data);
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? null;
            }

            $hasData = collect($row)->contains(fn($value) => $value !== null && $value !== '');
            if ($hasData) {
                fclose($handle);
                return $row;
            }
        }

        fclose($handle);

        return null;
    }
}

