<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

abstract class Strategy
{
    public function __construct(protected AssetMetrics $metrics)
    {
    }

    /**
     * Clone the strategy with updated metrics.
     */
    public function withMetrics(AssetMetrics $metrics): static
    {
        $clone = clone $this;
        $clone->metrics = $metrics;
        return $clone;
    }

    /**
     * Determine the action to take based on current metrics.
     *
     * @return string One of 'buy', 'sell', or 'hold'
     */
    abstract public function signal(): string;
}
