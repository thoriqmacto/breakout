<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;
use App\Services\IdxTicks;

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
        $ticks = IdxTicks::toTicks($this->multiplier * $atr, $prevClose);
        $offset = IdxTicks::fromTicks($ticks, $prevClose);
        $upper = $prevClose + $offset;
        $lower = $prevClose - $offset;

        if ($close > $upper) {
            return 'buy';
        }
        if ($close < $lower) {
            return 'sell';
        }
        return 'hold';
    }
}

