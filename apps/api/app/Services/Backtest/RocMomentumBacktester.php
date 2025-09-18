<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\RocMomentum;

class RocMomentumBacktester extends BaseBacktester
{
    public function __construct(
        private int $lookback = 13,
        private float $threshold = 5.0,
    ) {
    }

    protected function createStrategy(AssetMetrics $metrics): BaseStrategy
    {
        return new RocMomentum($metrics, $this->lookback, $this->threshold);
    }
}
