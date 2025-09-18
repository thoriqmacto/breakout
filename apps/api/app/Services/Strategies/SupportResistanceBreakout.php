<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class SupportResistanceBreakout extends BaseStrategy
{
    public function __construct(
        AssetMetrics $metrics,
        private int $weeks = 55,
        ?TrailingStop $trailingStop = null,
    ) {
        parent::__construct($metrics);
        if ($trailingStop) {
            $this->enableTrailingStop($trailingStop);
        }
    }

    public function signal(): string
    {
        // Require enough data to evaluate the requested lookback
        if ($this->metrics->barCount() <= $this->weeks * 5) {
            return 'hold';
        }

        $levels = $this->metrics->supportResistance($this->weeks);
        $close = $this->metrics->lastClose();

        if ($close > $levels['resistance']) {
            return 'buy';
        }

        if ($close < $levels['support']) {
            return 'sell';
        }

        return 'hold';
    }
}
