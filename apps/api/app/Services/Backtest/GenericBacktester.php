<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\BaseStrategy;

/**
 * Backtester that can run any strategy implementation.
 */
class GenericBacktester extends BaseBacktester
{
    public function __construct(private BaseStrategy $strategy) {}

    protected function createStrategy(AssetMetrics $metrics): BaseStrategy
    {
        return $this->strategy->withMetrics($metrics);
    }
}
