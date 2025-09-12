<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

abstract class Strategy
{
    public function __construct(protected AssetMetrics $metrics)
    {
    }

    /**
     * Determine the action to take based on current metrics.
     *
     * @return string One of 'buy', 'sell', or 'hold'
     */
    abstract public function signal(): string;
}
