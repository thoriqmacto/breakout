<?php

namespace Database\Seeders;

use App\Services\TradingDayWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Restore the trading-day calendar from the checked-in seed file.
 *
 * The seed file is generated from whatever the database held when
 * `trading-days:build` last ran, so it can contain sessions whose close was
 * unknown at that moment. Seeding used to upsert those straight over the
 * column, which meant `db:seed` could undo a Yahoo repair -- a session whose
 * value had since been recovered would go back to NULL because a file written
 * weeks earlier still said it was unknown.
 *
 * So the seeder writes through TradingDayWriter like every other path: it can
 * add sessions and it can fill in closes, and it cannot turn a known close
 * back into an unknown one.
 */
class TradingDaySeeder extends Seeder
{
    private string $dataPath;

    public function __construct(private readonly ?TradingDayWriter $writer = null)
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

        $payload = collect($records)
            ->map(function ($item) {
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
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($payload)) {
            $this->command?->warn('Trading day seeder data file does not contain valid records: '.$this->dataPath);

            return;
        }

        $writer = $this->writer ?? app(TradingDayWriter::class);
        $result = $writer->write($payload);

        $this->command?->info('Seeded '.count($payload).' trading day records.');

        if ($result['repaired'] !== []) {
            $this->command?->info('Filled in '.count($result['repaired']).' previously unknown close(s).');
        }

        if ($result['preserved'] !== []) {
            $this->command?->info(
                'Kept '.count($result['preserved']).' close(s) the seed file did not know; seeding never downgrades a known value.'
            );
        }
    }
}
