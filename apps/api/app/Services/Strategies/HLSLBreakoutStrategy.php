<?php

namespace App\Services\Strategies;

class HLSLBreakoutStrategy extends BaseStrategy
{
    /**
     * Annotate swing highs and lows using N=2 pivots.
     */
    public function detectSwings(array &$weeklyData, int $pivotLength = 2): void
    {
        $count = count($weeklyData);
        for ($i = 0; $i < $count; $i++) {
            $weeklyData[$i]['is_swing_high'] = false;
            $weeklyData[$i]['is_swing_low'] = false;
        }

        for ($i = $pivotLength; $i < $count - $pivotLength; $i++) {
            $window = array_slice($weeklyData, $i - $pivotLength, $pivotLength * 2 + 1);
            $highs = array_column($window, 'high');
            $lows = array_column($window, 'low');
            $centerHigh = $weeklyData[$i]['high'];
            $centerLow = $weeklyData[$i]['low'];

            if ($centerHigh === max($highs)) {
                $weeklyData[$i]['is_swing_high'] = true;
            }

            if ($centerLow === min($lows)) {
                $weeklyData[$i]['is_swing_low'] = true;
            }
        }
    }

    /**
     * Produce breakout signals when price and volume criteria align.
     *
     * @param  array<int, array<string, mixed>>  $weeklyData
     * @return array<int, array<string, mixed>>
     */
    public function generateSignals(array $weeklyData): array
    {
        $signals = [];
        $lastSwingHigh = null;

        foreach ($weeklyData as $index => $week) {
            $close = $week['close'];
            $volume = $week['volume'];
            $ema = $week['volume_ema'] ?? null;

            if ($lastSwingHigh !== null && $ema !== null && $ema > 0.0) {
                if ($close > $lastSwingHigh && $volume >= 1.2 * $ema) {
                    $signals[] = [
                        'week_index' => $index,
                        'week_end' => $week['end_date'],
                    ];
                }
            }

            if (! empty($week['is_swing_high'])) {
                $lastSwingHigh = $week['high'];
            }
        }

        return $signals;
    }

    /**
     * Map each week to the last observed swing low.
     *
     * @param  array<int, array<string, mixed>>  $weeklyData
     * @return array<string, float|null>
     */
    public function buildWeeklySwingLowMap(array $weeklyData): array
    {
        $map = [];
        $lastSwingLow = null;

        foreach ($weeklyData as $week) {
            $weekEndKey = $week['end_date']->toDateString();
            $map[$weekEndKey] = $lastSwingLow;

            if (! empty($week['is_swing_low'])) {
                $lastSwingLow = $week['low'];
            }
        }

        return $map;
    }

    /**
     * Complete analysis flow for convenience.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *     daily: array<int, array<string, mixed>>,
     *     weekly: array<int, array<string, mixed>>,
     *     signals: array<int, array<string, mixed>>
     * }
     */
    public function analyze(array $rows): array
    {
        $dailyData = $this->prepareDailyData($rows);
        if ($dailyData === []) {
            return [
                'daily' => [],
                'weekly' => [],
                'signals' => [],
            ];
        }

        $weeklyData = $this->resampleWeekly($dailyData);
        if (count($weeklyData) < 5) {
            return [
                'daily' => $dailyData,
                'weekly' => [],
                'signals' => [],
            ];
        }

        $this->detectSwings($weeklyData, 2);
        $this->computeVolumeEma($weeklyData, 20);
        $signals = $this->generateSignals($weeklyData);

        return [
            'daily' => $dailyData,
            'weekly' => $weeklyData,
            'signals' => $signals,
        ];
    }

    public function signal(): string
    {
        return 'hold';
    }
}
