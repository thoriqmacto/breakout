<?php

namespace App\Console\Commands;

use App\Models\TradingDay;
use App\Services\YahooTradingDays;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TradingDaysBuild extends Command
{
    protected $signature = 'trading-days:build {--from=2015-01-01} {--to=}';

    protected $description = 'Populate the trading_days table using Yahoo Finance historical data.';

    public function __construct(private readonly YahooTradingDays $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = $this->option('to');

        $count = $this->service->import($from, $to ?: null);

        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to ?: Carbon::now()->toDateString())->toDateString();

        $withClose = TradingDay::query()
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereNotNull('close')
            ->count();

        $this->info(sprintf(
            'Upserted %d trading day records (%d with a close value).',
            $count,
            $withClose
        ));

        try {
            $this->syncSeederFile();
        } catch (\Throwable $e) {
            $this->warn('Unable to update trading day seeder: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function syncSeederFile(): void
    {
        $records = TradingDay::query()
            ->orderBy('date')
            ->get(['date', 'close'])
            ->map(function (TradingDay $day) {
                $date = $day->getAttribute('date');

                if ($date instanceof Carbon) {
                    $date = $date->toDateString();
                } else {
                    $date = Carbon::parse((string) $date)->toDateString();
                }

                return [
                    'date' => $date,
                    'close' => $day->getAttribute('close'),
                ];
            })
            ->values()
            ->all();

        if (count($records) === 0) {
            $this->warn('No trading day records available to write to the seeder file.');

            return;
        }

        $path = database_path('seeders/data/trading_days.php');
        $existing = $this->readSeederData($path);

        if ($existing !== null && $this->recordsAreEqual($existing, $records)) {
            $this->info('Trading day seeder data is already up to date.');

            return;
        }

        File::ensureDirectoryExists(dirname($path));

        $contents = "<?php\n\nreturn ".$this->exportArray($records).";\n";

        if (File::put($path, $contents) === false) {
            throw new \RuntimeException('Failed to write trading day seeder data.');
        }

        $this->info('Trading day seeder data saved to '.$path.'.');
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readSeederData(string $path): ?array
    {
        if (! File::exists($path)) {
            return null;
        }

        $data = include $path;

        if (! is_array($data)) {
            return null;
        }

        return Collection::make($data)
            ->map(function ($item) {
                if (! is_array($item) || ! isset($item['date'])) {
                    return null;
                }

                try {
                    $date = Carbon::parse((string) $item['date'])->toDateString();
                } catch (\Throwable) {
                    return null;
                }

                return [
                    'date' => $date,
                    'close' => $item['close'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $first
     * @param  array<int, array<string, mixed>>  $second
     */
    private function recordsAreEqual(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        return $first == $second; // phpcs:ignore
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function exportArray(array $records): string
    {
        $export = var_export($records, true);

        $export = preg_replace('/^([ ]*)array \(/m', '$1[', $export);
        $export = preg_replace('/\)(,?)$/m', ']$1', $export);

        return str_replace('NULL', 'null', (string) $export);
    }
}
