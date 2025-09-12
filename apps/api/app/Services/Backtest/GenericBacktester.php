<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\Strategy;

/**
 * Backtester that can run any strategy implementation.
 */
class GenericBacktester extends Backtester
{
    public function __construct(private Strategy $strategy)
    {
    }

    protected function createStrategy(AssetMetrics $metrics): Strategy
    {
        return $this->strategy->withMetrics($metrics);
    }
}

