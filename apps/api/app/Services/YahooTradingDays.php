<?php

namespace App\Services;

use App\Models\TradingDay;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class YahooTradingDays
{
    private const SYMBOL = '^JKSE';
    private const SCRIPT = 'get_stocks.py';
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly PythonRunner $pythonRunner,
        private readonly TradingDay $tradingDay,
    ) {
    }

    public function import(string $from = '2015-01-01', ?string $to = null): int
    {
        $startDate = Carbon::parse($from)->startOfDay();
        $endDate = $to ? Carbon::parse($to)->endOfDay() : Carbon::now();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date must be greater than or equal to the start date.');
        }

        $args = [
            self::SYMBOL,
            sprintf('--start=%s', $startDate->toDateString()),
            sprintf('--end=%s', $endDate->copy()->toDateString()),
            '--emit-dates',
        ];

        $result = $this->pythonRunner->run(self::SCRIPT, null, $args);

        if (!$result['ok']) {
            $errorMessage = trim($result['stderr'] ?: $result['stdout']);
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Failed to fetch trading days from Python script.');
        }

        $payload = $result['json'];

        if (!is_array($payload)) {
            return 0;
        }

        $tickerData = Collection::make($payload['tickers'] ?? [])
            ->first(function (mixed $item) {
                return is_array($item) && ($item['ticker'] ?? null) === self::SYMBOL;
            });

        if (!is_array($tickerData)) {
            return 0;
        }

        $dates = $tickerData['dates'] ?? [];

        $now = Carbon::now();

        $records = Collection::make($dates)
            ->filter()
            ->map(function (mixed $date) use ($now, $startDate, $endDate) {
                if (!is_string($date)) {
                    return null;
                }

                try {
                    $parsed = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                } catch (InvalidFormatException) {
                    return null;
                }

                if ($parsed->lessThan($startDate) || $parsed->greaterThan($endDate)) {
                    return null;
                }

                return [
                    'date' => $parsed->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->unique('date')
            ->sortBy('date')
            ->values();

        if ($records->isEmpty()) {
            return 0;
        }

        $records
            ->chunk(self::CHUNK_SIZE)
            ->each(function (Collection $chunk): void {
                $this->tradingDay->newQuery()->upsert(
                    $chunk->all(),
                    ['date'],
                    ['updated_at']
                );
            });

        return $records->count();
    }
}
