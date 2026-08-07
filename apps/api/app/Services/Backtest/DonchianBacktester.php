<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\DonchianBreakout;

class DonchianBacktester extends BaseBacktester
{
    public function __construct(private int $period = 3) {}

    protected function createStrategy(AssetMetrics $metrics): BaseStrategy
    {
        return new DonchianBreakout($metrics, $this->period);
    }
}
