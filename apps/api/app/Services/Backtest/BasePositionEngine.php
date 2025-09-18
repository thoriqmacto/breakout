<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;

/**
 * Shared functionality for stateful position engines.
 */
abstract class BasePositionEngine
{
    /**
     * @var array<int, array{date:string, open:float, high:float, low:float, close:float, volume?:float}>
     */
    protected array $history = [];

    /**
     * Append a new bar to the internal price history.
     *
     * @param array{date:string, open:float, high:float, low:float, close:float, volume?:float} $bar
     */
    protected function recordBar(array $bar): void
    {
        $this->history[] = $bar;
    }

    /**
     * Retrieve the most recent bar that has been processed.
     *
     * @return array{date:string, open:float, high:float, low:float, close:float, volume?:float}|null
     */
    protected function lastBar(): ?array
    {
        return $this->history[array_key_last($this->history)] ?? null;
    }

    /**
     * Retrieve the bar immediately preceding the latest observation.
     *
     * @return array{date:string, open:float, high:float, low:float, close:float, volume?:float}|null
     */
    protected function previousBar(): ?array
    {
        $count = count($this->history);
        if ($count < 2) {
            return null;
        }

        return $this->history[$count - 2];
    }

    /**
     * Create an AssetMetrics helper for the current price history.
     */
    protected function metrics(): AssetMetrics
    {
        return new AssetMetrics($this->history);
    }

    /**
     * Highest high observed within the requested lookback window.
     *
     * When $requireFullPeriod is true, the method will only return a level when
     * the engine has observed at least $days prior bars.  This avoids reporting
     * inflated levels (e.g. treating a 55-week breakout as valid when fewer
     * than 55 weeks of history have been processed).
     */
    protected function rollingHigh(int $days, bool $requireFullPeriod = false): float
    {
        $count = count($this->history);
        if ($count < 2) {
            return 0.0;
        }

        if ($requireFullPeriod && $count - 1 < $days) {
            return 0.0;
        }

        $lookback = min($days, $count - 1);
        if ($lookback <= 0) {
            return 0.0;
        }

        $slice = array_slice($this->history, -($lookback + 1), $lookback);
        if ($slice === []) {
            return 0.0;
        }

        $values = array_map(static fn (array $bar): float => $bar['close'], $slice);

        return (float) max($values);
    }
}
