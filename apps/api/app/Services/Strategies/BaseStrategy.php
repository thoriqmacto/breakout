<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;
use Carbon\Carbon;

abstract class BaseStrategy
{
    public function __construct(protected ?AssetMetrics $metrics = null)
    {
    }

    private ?TrailingStop $trailingStop = null;

    /**
     * Clone the strategy with updated metrics.
     */
    public function withMetrics(AssetMetrics $metrics): static
    {
        $clone = clone $this;
        $clone->metrics = $metrics;
        return $clone;
    }

    public function enableTrailingStop(TrailingStop $stop): void
    {
        $this->trailingStop = $stop;
    }

    public function trailingStop(): ?TrailingStop
    {
        return $this->trailingStop;
    }

    /**
     * Normalize incoming OHLCV rows into sorted Carbon-backed structures.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function prepareDailyData(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (! isset($row['date'], $row['open'], $row['high'], $row['low'], $row['close'], $row['volume'])) {
                continue;
            }

            $date = Carbon::parse($row['date'])->startOfDay();
            $clean[] = [
                'date' => clone $date,
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'volume' => (float) $row['volume'],
            ];
        }

        usort($clean, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $clean;
    }

    /**
     * Group daily bars into weeks ending on the provided day (default Friday).
     *
     * @param array<int, array<string, mixed>> $dailyData
     * @return array<int, array<string, mixed>>
     */
    protected function resampleWeekly(array $dailyData, int $weekEndingDay = Carbon::FRIDAY): array
    {
        $weeks = [];

        foreach ($dailyData as $index => $day) {
            $weekEnd = (clone $day['date'])->endOfWeek($weekEndingDay);
            $key = $weekEnd->toDateString();

            if (! isset($weeks[$key])) {
                $weeks[$key] = [
                    'start_date' => clone $day['date'],
                    'end_date' => $weekEnd,
                    'open' => $day['open'],
                    'high' => $day['high'],
                    'low' => $day['low'],
                    'close' => $day['close'],
                    'volume' => $day['volume'],
                    'daily_indices' => [$index],
                ];
            } else {
                $week = &$weeks[$key];
                $week['high'] = max($week['high'], $day['high']);
                $week['low'] = min($week['low'], $day['low']);
                $week['close'] = $day['close'];
                $week['volume'] += $day['volume'];
                $week['daily_indices'][] = $index;
                unset($week);
            }
        }

        $result = array_values($weeks);
        usort($result, fn ($a, $b) => $a['end_date'] <=> $b['end_date']);

        return $result;
    }

    /**
     * Append an exponential moving average for a numeric field on each record.
     *
     * @param array<int, array<string, mixed>> $records
     */
    protected function computeVolumeEma(
        array &$records,
        int $period = 20,
        string $sourceKey = 'volume',
        string $targetKey = 'volume_ema',
    ): void {
        $alpha = 2 / ($period + 1);
        $ema = null;

        foreach ($records as &$record) {
            $value = (float) ($record[$sourceKey] ?? 0.0);
            $ema = $ema === null ? $value : ($value - $ema) * $alpha + $ema;
            $record[$targetKey] = $ema;
        }
    }

    /**
     * Determine the action to take based on current metrics.
     *
     * @return string One of 'buy', 'sell', or 'hold'
     */
    abstract public function signal(): string;
}
