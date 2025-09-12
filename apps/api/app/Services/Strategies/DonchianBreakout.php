<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class DonchianBreakout extends Strategy
{
    public function __construct(
        AssetMetrics $metrics,
        private int $period = 20,
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

        $channel = $this->metrics->donchianChannel($this->period, false);
        $close = $this->metrics->lastClose();

        if ($close > $channel['upper']) {
            return 'buy';
        }
        if ($close < $channel['lower']) {
            return 'sell';
        }
        return 'hold';
    }
}
