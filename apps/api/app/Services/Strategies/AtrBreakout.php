<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class AtrBreakout extends Strategy
{
    public function __construct(
        AssetMetrics $metrics,
        private float $multiplier = 1.0,
        private int $period = 14,
        ?TrailingStop $trailingStop = null,
    ) {
        parent::__construct($metrics);
        if ($trailingStop) {
            $this->enableTrailingStop($trailingStop);
        }
    }

    public function signal(): string
    {
        if ($this->metrics->barCount() <= $this->period) {
            return 'hold';
        }

        $close = $this->metrics->lastClose();
        $prevClose = $this->metrics->previousClose();
        $atr = $this->metrics->atr($this->period);
        $upper = $prevClose + $this->multiplier * $atr;
        $lower = $prevClose - $this->multiplier * $atr;

        if ($close > $upper) {
            return 'buy';
        }
        if ($close < $lower) {
            return 'sell';
        }
        return 'hold';
    }
}

