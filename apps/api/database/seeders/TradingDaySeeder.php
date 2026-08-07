<?php

namespace Database\Seeders;

use App\Models\TradingDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TradingDaySeeder extends Seeder
{
    private string $dataPath;

    public function __construct()
    {
        $this->dataPath = database_path('seeders/data/trading_days.php');
    }

    public function run(): void
    {
        if (! File::exists($this->dataPath)) {
            $this->command?->warn('Trading day seeder data file not found: '.$this->dataPath);

            return;
        }

        $records = include $this->dataPath;

        if (! is_array($records) || empty($records)) {
            $this->command?->warn('Trading day seeder data file is empty: '.$this->dataPath);

            return;
        }

        $now = Carbon::now();

        $payload = collect($records)
            ->map(function ($item) use ($now) {
                if (! is_array($item) || ! isset($item['date'])) {
                    return null;
                }

                $date = Str::of($item['date'])->trim();
                if ($date->isEmpty()) {
                    return null;
                }

                try {
                    $parsed = Carbon::parse($date->toString())->toDateString();
                } catch (\Throwable) {
                    return null;
                }

                $close = $item['close'] ?? null;
                if ($close !== null && ! is_numeric($close)) {
                    $close = null;
                }

                return [
                    'date' => $parsed,
                    'close' => $close === null ? null : (float) $close,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($payload)) {
            $this->command?->warn('Trading day seeder data file does not contain valid records: '.$this->dataPath);

            return;
        }

        TradingDay::query()->upsert(
            $payload,
            ['date'],
            ['close', 'updated_at']
        );

        $this->command?->info('Seeded '.count($payload).' trading day records.');
    }
}
