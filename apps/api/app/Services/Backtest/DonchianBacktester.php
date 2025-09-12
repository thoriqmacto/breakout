<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\DonchianBreakout;
use App\Services\Strategies\Strategy;

class DonchianBacktester extends Backtester
{
    public function __construct(private int $period = 3)
    {
    }

    protected function createStrategy(AssetMetrics $metrics): Strategy
    {
        return new DonchianBreakout($metrics, $this->period);
    }
}
