<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Helper methods for calculating technical indicators from price data.
 */
class AssetMetrics
{
    /**
     * Calculate metrics for the given asset.
     *
     * @param  \App\Models\Asset  $asset
     * @return array<string, float|int|null>
     */
    public function forAsset(Asset $asset): array
    {
        // Retrieve ordered price data for the asset
        $prices = $asset->prices()->orderBy('date')->get();

        if ($prices->isEmpty()) {
            return [
                'average_price' => null,
                'total_volume' => 0,
            ];
        }

        return [
            'average_price' => $prices->avg('close'),
            'total_volume' => (int) $prices->sum('volume'),
        ];
    }
  
    /**
     * Convert the input bars into a simple array.
     */
    private static function toArray(array|Collection $bars): array
    {
        return $bars instanceof Collection ? $bars->all() : $bars;
    }

    /**
     * Extract a numeric field from a price bar.
     */
    private static function field(mixed $bar, string $name): float
    {
        if (is_array($bar)) {
            $v = $bar[$name] ?? null;
        } else {
            $v = $bar->$name ?? (method_exists($bar, 'getAttribute') ? $bar->getAttribute($name) : null);
        }
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * Average True Range using prior close.
     *
     * @param array|Collection $bars   Price bars ordered by date (latest last).
     * @param int              $period Number of periods to average.
     * @return float Average true range or 0 when insufficient data.
     */
    public static function atr(array|Collection $bars, int $period = 14): float
    {
        $arr = self::toArray($bars);
        $count = count($arr);
        if ($count < 2) return 0.0;
        $start = max(1, $count - $period);
        $sum = 0.0; $n = 0;
        for ($i = $start; $i < $count; $i++) {
            $bar = $arr[$i];
            $prev = $arr[$i - 1];
            $high = self::field($bar, 'high');
            $low  = self::field($bar, 'low');
            $prevClose = self::field($prev, 'close');
            $tr = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            $sum += $tr; $n++;
        }
        return $n > 0 ? $sum / $n : 0.0;
    }

    /**
     * Highest high over a number of weeks.
     *
     * @param array|Collection $bars  Bars ordered by date (weekly or daily).
     * @param int              $weeks Number of weeks to look back.
     * @return float Highest high or 0 when no data.
     */
    public static function periodHigh(array|Collection $bars, int $weeks): float
    {
        $arr = self::toArray($bars);
        if (empty($arr)) return 0.0;
        $slice = array_slice($arr, -$weeks);
        $max = null;
        foreach ($slice as $bar) {
            $h = self::field($bar, 'high');
            if ($max === null || $h > $max) $max = $h;
        }
        return $max ?? 0.0;
    }

    /**
     * Lowest low over a number of weeks.
     *
     * @param array|Collection $bars  Bars ordered by date (weekly or daily).
     * @param int              $weeks Number of weeks to look back.
     * @return float Lowest low or 0 when no data.
     */
    public static function periodLow(array|Collection $bars, int $weeks): float
    {
        $arr = self::toArray($bars);
        if (empty($arr)) return 0.0;
        $slice = array_slice($arr, -$weeks);
        $min = null;
        foreach ($slice as $bar) {
            $l = self::field($bar, 'low');
            if ($min === null || $l < $min) $min = $l;
        }
        return $min ?? 0.0;
    }

    /**
     * Simple moving average over a number of days.
     *
     * @param array|Collection $bars Daily bars ordered by date.
     * @param int              $days Number of days to average.
     * @return float Moving average or 0 when no data.
     */
    public static function movingAverage(array|Collection $bars, int $days): float
    {
        $arr = self::toArray($bars);
        if (empty($arr)) return 0.0;
        $slice = array_slice($arr, -$days);
        $sum = 0.0; $n = 0;
        foreach ($slice as $bar) {
            $sum += self::field($bar, 'close');
            $n++;
        }
        return $n > 0 ? $sum / $n : 0.0;
    }

    /**
     * Moving average over a number of weeks (assuming 5 trading days per week).
     *
     * @param array|Collection $bars Daily bars ordered by date.
     * @param int              $weeks Number of weeks to average.
     * @return float Moving average or 0 when no data.
     */
    public static function movingAverageWeeks(array|Collection $bars, int $weeks): float
    {
        return self::movingAverage($bars, $weeks * 5);
    }

    /**
     * Support levels (lows below the latest close) within a lookback window.
     *
     * @param array|Collection $bars     Bars ordered by date (latest last).
     * @param int              $lookback Number of recent bars to inspect.
     * @return array<float> Unique support levels sorted descending.
     */
    public static function supportLevels(array|Collection $bars, int $lookback): array
    {
        $arr = self::toArray($bars);
        $count = count($arr);
        if ($count === 0) return [];
        $close = self::field($arr[$count - 1], 'close');
        $start = max(0, $count - $lookback);
        $levels = [];
        for ($i = $start; $i < $count; $i++) {
            $low = self::field($arr[$i], 'low');
            if ($low <= $close) $levels[] = $low;
        }
        $levels = array_values(array_unique($levels));
        rsort($levels);
        return $levels;
    }

    /**
     * Resistance levels (highs above the latest close) within a lookback window.
     *
     * @param array|Collection $bars     Bars ordered by date (latest last).
     * @param int              $lookback Number of recent bars to inspect.
     * @return array<float> Unique resistance levels sorted ascending.
     */
    public static function resistanceLevels(array|Collection $bars, int $lookback): array
    {
        $arr = self::toArray($bars);
        $count = count($arr);
        if ($count === 0) return [];
        $close = self::field($arr[$count - 1], 'close');
        $start = max(0, $count - $lookback);
        $levels = [];
        for ($i = $start; $i < $count; $i++) {
            $high = self::field($arr[$i], 'high');
            if ($high >= $close) $levels[] = $high;
        }
        $levels = array_values(array_unique($levels));
        sort($levels);
        return $levels;
    }

    /**
     * Determine if the latest close is above a given price level.
     *
     * @param array|Collection $bars  Bars ordered by date (latest last).
     * @param float            $price Price level to compare.
     * @return bool True when the latest close is above the level.
     */
    public static function isAbove(array|Collection $bars, float $price): bool
    {
        $arr = self::toArray($bars);
        if (empty($arr)) return false;
        $close = self::field($arr[count($arr) - 1], 'close');
        return $close > $price;
    }

    /**
     * Percentage rate-of-change over a number of weeks.
     *
     * @param array|Collection $bars  Daily bars ordered by date.
     * @param int              $weeks Number of weeks to look back.
     * @return float Percentage change or 0 when insufficient data.
     */
    public static function rocWeeks(array|Collection $bars, int $weeks = 13): float
    {
        $arr = self::toArray($bars);
        $count = count($arr);
        $period = $weeks * 5;
        if ($count === 0) return 0.0;
        $idx = $count - 1 - $period;
        if ($idx < 0) return 0.0;
        $latest = self::field($arr[$count - 1], 'close');
        $past = self::field($arr[$idx], 'close');
        if ($past == 0.0) return 0.0;
        return (($latest / $past) - 1.0) * 100.0;
    }

    /**
     * Determine if the latest close breaks above a supplied level.
     *
     * @param array|Collection $bars  Bars ordered by date (latest last).
     * @param float            $level Price level to test.
     * @return bool True when the latest close crosses above the level.
     */
    public static function isBreakout(array|Collection $bars, float $level): bool
    {
        $arr = self::toArray($bars);
        $count = count($arr);
        if ($count < 2) return false;
        $prev = self::field($arr[$count - 2], 'close');
        $close = self::field($arr[$count - 1], 'close');
        return $prev <= $level && $close > $level;
    }
}
