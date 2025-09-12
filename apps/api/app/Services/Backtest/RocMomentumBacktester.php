<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\RocMomentum;
use App\Services\Strategies\Strategy;

class RocMomentumBacktester extends Backtester
{
    public function __construct(
        private int $lookback = 13,
        private float $threshold = 5.0,
    ) {
    }

    protected function createStrategy(AssetMetrics $metrics): Strategy
    {
        return new RocMomentum($metrics, $this->lookback, $this->threshold);
    }
}
