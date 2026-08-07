<?php

namespace App\Services;

/**
 * IDX variable tick ladder (Fractional Price Rules).
 * Bands (current standard):
 *  < 200        => 1
 *  200 – <500   => 2
 *  500 – <2,000 => 5
 *  2,000 – <5,000 => 10
 *  >= 5,000     => 25
 */
final class IdxTicks
{
    public static function tickFor(float $price): float
    {
        if ($price < 200) {
            return 1.0;
        }
        if ($price < 500) {
            return 2.0;
        }
        if ($price < 2000) {
            return 5.0;
        }
        if ($price < 5000) {
            return 10.0;
        }

        return 25.0;
    }

    /** Round to nearest valid IDX price tick around a reference price (default: the value itself). */
    public static function round(float $value, ?float $refPrice = null): float
    {
        $t = self::tickFor($refPrice ?? $value);

        return round($value / $t) * $t;
    }

    /** Floor to tick (never above value). */
    public static function floor(float $value, ?float $refPrice = null): float
    {
        $t = self::tickFor($refPrice ?? $value);

        return floor($value / $t) * $t;
    }

    /** Ceil to tick (never below value). */
    public static function ceil(float $value, ?float $refPrice = null): float
    {
        $t = self::tickFor($refPrice ?? $value);

        return ceil($value / $t) * $t;
    }

    /** Convert a raw price delta into an integer count of ticks at a reference price. */
    public static function toTicks(float $delta, float $refPrice): int
    {
        $t = max(self::tickFor($refPrice), 1e-9);

        return (int) round($delta / $t);
    }

    /** Convert a tick count into a price delta at a reference price. */
    public static function fromTicks(int $ticks, float $refPrice): float
    {
        return $ticks * self::tickFor($refPrice);
    }
}
