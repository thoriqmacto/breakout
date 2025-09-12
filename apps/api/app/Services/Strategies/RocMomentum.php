<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class RocMomentum extends Strategy
{
    public function __construct(
        AssetMetrics $metrics,
        private int $lookback = 13,
        private float $threshold = 5.0,
    ) {
        parent::__construct($metrics);
    }

    public function signal(): string
    {
        if ($this->metrics->barCount() <= $this->lookback * 5) {
            return 'hold';
        }

        $roc = $this->metrics->rocWeeks($this->lookback);

        if ($roc >= $this->threshold) {
            return 'buy';
        }
        if ($roc <= -$this->threshold) {
            return 'sell';
        }
        return 'hold';
    }
}
