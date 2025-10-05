<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Price;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Utility class for common asset price calculations.
 *
 * This class operates on an array of OHLCV bars ordered by date.
 * Each bar is an associative array with keys: date, open, high, low, close, volume.
 */
class AssetMetrics
{
    /**
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float, volume?:float}> $bars
     */
    public function __construct(private array $bars){}

    public function lastClose(): float
    {
        return $this->bars[array_key_last($this->bars)]['close'];
    }

    private function parseDate(array $bar): ?\DateTimeImmutable
    {
        $raw = $bar['date'] ?? null;
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $formats = [
            '!Y-m-d',
            '!d/m/Y',
            '!m/d/Y',
            '!Y/m/d',
            \DateTimeInterface::ATOM,
            \DateTimeInterface::RFC3339_EXTENDED,
            \DateTimeInterface::RFC3339,
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);
            if (!$parsed instanceof \DateTimeImmutable) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();
            $warnings = is_array($errors) ? ($errors['warning_count'] ?? 0) : 0;
            $errorCount = is_array($errors) ? ($errors['error_count'] ?? 0) : 0;

            if ($warnings === 0 && $errorCount === 0) {
                return $parsed;
            }
        }

        return null;
    }

    public function lastVolume(): float
    {
        $last = $this->bars[array_key_last($this->bars)] ?? null;
        return $last ? $this->field($last, 'volume') : 0.0;
    }

    public function previousClose(): float
    {
        $count = count($this->bars);
        if ($count < 2) {
            return 0.0;
        }
        return $this->bars[$count - 2]['close'];
    }

    public function barCount(): int
    {
        return count($this->bars);
    }

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
                'average_volume_20d' => 0,
            ];
        }

        return [
            'average_price' => $prices->avg('close'),
            'total_volume' => (int) $prices->sum('volume'),
            'average_volume_20d' => (int) round($prices->take(-20)->avg('volume')),
        ];
    }

    /**
     * Extract a numeric field from a price bar.
     *
     * @param array|object $bar  The price bar to inspect.
     * @param string       $name Field to retrieve.
     */
    private function field(array|object $bar, string $name): float
    {
        if (is_array($bar)) {
            $v = $bar[$name] ?? null;
        } else {
            $v = $bar->$name ?? (method_exists($bar, 'getAttribute') ? $bar->getAttribute($name) : null);
        }
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Average True Range using prior close.
     *
     * @param int              $period Number of periods to average.
     * @param string           $mode   Calculation variant ("standard" or "weekly").
     * @return float Average true range or 0 when insufficient data.
     */
    public function atr(int $period = 14, string $mode = 'standard'): float
    {
        $totalBars = count($this->bars);
        if ($period <= 0 || $totalBars === 0) {
            return 0.0;
        }

        $slice = $period >= $totalBars
            ? $this->bars
            : array_slice($this->bars, -$period);

        $startIndex = $totalBars - count($slice);
        $prevClose = null;
        if ($mode === 'weekly' && $startIndex > 0) {
            $previousBar = $this->bars[$startIndex - 1] ?? null;
            if (is_array($previousBar) && isset($previousBar['close'])) {
                $prevClose = (float) $previousBar['close'];
            }
        }

        $trueRanges = [];
        foreach ($slice as $bar) {
            $high = (float) ($bar['high'] ?? 0.0);
            $low = (float) ($bar['low'] ?? 0.0);
            $close = (float) ($bar['close'] ?? 0.0);

            if ($mode === 'weekly') {
                if ($prevClose === null) {
                    $tr = abs($close - $low);
                } else {
                    $tr = max(abs($high - $prevClose), abs($close - $low));
                }
            } else {
                $tr = $high - $low;
                if ($prevClose !== null) {
                    $tr = max($tr, abs($high - $prevClose), abs($low - $prevClose));
                }
            }

            $trueRanges[] = $tr;
            $prevClose = $close;
        }

        $count = count($trueRanges);
        return $count > 0 ? array_sum($trueRanges) / $count : 0.0;
    }

    /**
     * Highest high over a number of weeks.
     *
     * @param int   $weeks Number of weeks to look back.
     * @return float Highest high or 0 when no data.
     */
    public function periodHigh(int $weeks): float
    {
        $days = $weeks * 5;
        $slice = array_slice($this->bars, -$days);
        if (empty($slice)) {
            return 0.0;
        }
        return max(array_map(fn($b) => $b['high'], $slice));
    }

    /**
     * Simple moving average over a number of days.
     *
     * @param int              $days Number of days to average.
     * @return float Moving average or 0 when no data.
     */
    public function movingAverage(int $days): float
    {
        $slice = array_slice($this->bars, -$days);
        if (empty($slice)) {
            return 0.0;
        }
        $sum = array_sum(array_map(fn($b) => $b['close'], $slice));
        return $sum / count($slice);
    }

    public function movingAverageTradingDays(int $days = 150): float
    {
        if (empty($this->bars)) {
            return 0.0;
        }

        $rows = $this->bars;
        usort($rows, function (array $a, array $b): int {
            $da = $this->parseDate($a);
            $db = $this->parseDate($b);
            if ($da === null && $db === null) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }
            return $da <=> $db;
        });

        $closes = [];
        foreach ($rows as $bar) {
            if ($this->parseDate($bar) === null) {
                continue;
            }
            $closes[] = (float) $bar['close'];
        }
        $slice = array_slice($closes, -$days);
        $count = count($slice);
        return $count ? array_sum($slice) / $count : 0.0;
    }

    /**
     * Average volume over a number of days.
     *
     * @param int $days Number of days to average.
     * @return float Average volume or 0 when no data.
     */
    public function averageVolume(int $days = 20): float
    {
        $slice = array_slice($this->bars, -$days);
        if (empty($slice)) {
            return 0.0;
        }
        $sum = array_sum(array_map(fn($b) => $this->field($b, 'volume'), $slice));
        return $sum / count($slice);
    }

    /**
     * Moving average over a number of weeks (assuming 5 trading days per week).
     *
     * @param int              $weeks Number of weeks to average.
     * @return float Moving average or 0 when no data.
     */
    public function movingAverageWeeks(int $weeks = 30): float
    {
        if (empty($this->bars)) {
            return 0.0;
        }

        $rows = $this->bars;
        usort($rows, function (array $a, array $b): int {
            $da = $this->parseDate($a);
            $db = $this->parseDate($b);
            if ($da === null && $db === null) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }
            return $da <=> $db;
        });

        $weeklyLast = [];
        foreach ($rows as $bar) {
            $date = $this->parseDate($bar);
            if ($date === null) {
                continue;
            }

            $weekKey = $date->format('o-W');
            $weeklyLast[$weekKey] = (float) $bar['close'];
        }

        if ($weeklyLast === []) {
            return 0.0;
        }

        $currentWeekKey = (new \DateTimeImmutable('now'))->format('o-W');

        $completed = [];
        foreach ($weeklyLast as $key => $close) {
            if ($key !== $currentWeekKey) {
                $completed[$key] = $close;
            }
        }

        if ($completed === []) {
            return 0.0;
        }

        $window = array_slice($completed, -$weeks, null, true);
        $values = array_values($window);
        $count = count($values);
        return $count ? array_sum($values) / $count : 0.0;
    }

    /**
     * Determine support and resistance levels within lookback weeks.
     *
     * The returned levels are based solely on prior bars and exclude the most
     * recent bar so that they represent established price levels.  This allows
     * breakout strategies to compare the latest close against historical
     * support and resistance and react when those levels are breached.
     *
     * @param int               $weeks Number of recent weeks to inspect.
     * @return array<float>     an array with keys `support` and `resistance`.
     */
    public function supportResistance(int $weeks): array
    {
        $days = $weeks * 5;
        // Exclude the most recent bar so levels come from prior history only
        $slice = array_slice($this->bars, -$days - 1, $days);
        if (empty($slice)) {
            return ['support' => 0.0, 'resistance' => 0.0];
        }

        // Support is the highest low from prior bars; resistance is the highest
        // high.  These represent the key levels that the current close may
        // break above or below.
        $support = max(array_column($slice, 'low'));
        $resistance = max(array_column($slice, 'high'));

        return ['support' => (float) $support, 'resistance' => (float) $resistance];
    }

    /**
     * Determine if the latest close is above a given price level.
     *
     * @param float            $price Price level to compare.
     * @return bool True when the latest close is above the level.
     */
    public function isAbove(float $price): bool
    {
        if (empty($this->bars)) return false;
        $close = $this->field($this->bars[count($this->bars) - 1], 'close');
        return $close > $price;
    }

    /**
     * Percentage rate-of-change over a number of weeks.
     *
     * @param int              $weeks Number of weeks to look back.
     * @return float Percentage change or 0 when insufficient data.
     */
    public function rocWeeks(int $weeks = 13): float
    {
        $days = $weeks * 5;
        $index = count($this->bars) - $days - 1;
        $past = $this->bars[$index]['close'];
        $current = $this->lastClose();
        return (($current - $past) / $past) * 100;
    }

    /**
     * Relative Strength Index based on closing prices.
     *
     * Calculates the average gain and loss over the supplied period using
     * consecutive closing prices.  When there is insufficient data the value
     * 0 is returned.
     */
    public function relativeStrengthIndex(int $period = 14): float
    {
        if ($this->barCount() <= $period) {
            return 0.0;
        }

        $slice = array_slice($this->bars, -($period + 1));

        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1, $len = count($slice); $i < $len; $i++) {
            $change = $slice[$i]['close'] - $slice[$i - 1]['close'];
            if ($change > 0) {
                $gain += $change;
            } elseif ($change < 0) {
                $loss -= $change; // make positive
            }
        }

        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;

        if ($avgLoss == 0.0) {
            return $avgGain > 0 ? 100.0 : 0.0;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    /**
     * Highest high and lowest low over the given lookback.
     *
     * @param int  $period          Number of recent bars to inspect.
     * @param bool $includeCurrent  When false, exclude the latest bar.
     * @return array{upper: float, lower: float}
     */
    public function donchianChannel(int $period = 20, bool $includeCurrent = true): array
    {
        if ($includeCurrent) {
            $slice = array_slice($this->bars, -$period);
        } else {
            if (count($this->bars) <= $period) {
                return ['upper' => 0.0, 'lower' => 0.0];
            }
            $slice = array_slice($this->bars, -$period - 1, $period);
        }

        if (empty($slice)) {
            return ['upper' => 0.0, 'lower' => 0.0];
        }

        $highs = array_map(fn($b) => $b['high'], $slice);
        $lows = array_map(fn($b) => $b['low'], $slice);

        return [
            'upper' => (float)max($highs),
            'lower' => (float)min($lows),
        ];
    }

    /**
     * Determine if the latest close breaks above a supplied level.
     *
     * @param float            $level Price level to test.
     * @return bool True when the latest close crosses above the level.
     */
    public function isBreakout(float $level): bool
    {
        return $this->lastClose() > $level;
    }

    /**
     * True when latest close is above the 30-week moving average.
     */
    public function isUptrend(): bool
    {
        return $this->lastClose() > $this->movingAverageWeeks(30);
    }

    /**
     * Build normalized daily bars from a collection of price models.
     *
     * @param  Collection<int, Price>  $prices
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:float}>
     */
    public static function buildDailyBars(Collection $prices): array
    {
        return $prices->map(function (Price $price): array {
            $date = self::normalizePriceDate($price);

            return [
                'date' => $date?->format('Y-m-d') ?? (string) $price->date,
                'open' => (float) $price->open,
                'high' => (float) $price->high,
                'low' => (float) $price->low,
                'close' => (float) $price->close,
                'volume' => (float) $price->volume,
            ];
        })->all();
    }

    /**
     * Build normalized weekly bars by aggregating a collection of price models.
     *
     * @param  Collection<int, Price>  $prices
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:float}>
     */
    public static function buildWeeklyBars(Collection $prices): array
    {
        $bars = [];

        foreach ($prices as $price) {
            $date = self::normalizePriceDate($price);

            if (!$date instanceof CarbonImmutable) {
                continue;
            }

            $key = $date->format('o-W');

            if (!isset($bars[$key])) {
                $bars[$key] = [
                    'date' => $date->format('Y-m-d'),
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                    'volume' => (float) $price->volume,
                ];

                continue;
            }

            $bars[$key]['high'] = max($bars[$key]['high'], (float) $price->high);
            $bars[$key]['low'] = min($bars[$key]['low'], (float) $price->low);
            $bars[$key]['close'] = (float) $price->close;
            $bars[$key]['volume'] += (float) $price->volume;
            $bars[$key]['date'] = $date->format('Y-m-d');
        }

        return array_values($bars);
    }

    /**
     * Normalize various price date representations to an immutable Carbon instance.
     */
    public static function normalizePriceDate(Price $price): ?CarbonImmutable
    {
        $value = $price->date;

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::make($value);
        }

        return null;
    }
}
