<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class MovingAverageCrossover extends Strategy
{
    public function __construct(
        AssetMetrics $metrics,
        private int $shortPeriod = 50,
        private int $longPeriod = 200,
    ) {
        parent::__construct($metrics);
    }

    public function signal(): string
    {
        if ($this->metrics->barCount() <= $this->longPeriod) {
            return 'hold';
        }

        $short = $this->metrics->movingAverage($this->shortPeriod);
        $long = $this->metrics->movingAverage($this->longPeriod);

        if ($short > $long) {
            return 'buy';
        }

        if ($short < $long) {
            return 'sell';
        }

        return 'hold';
    }
}
