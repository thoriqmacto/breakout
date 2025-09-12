<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

class RsiReversal extends Strategy
{
    public function __construct(
        AssetMetrics $metrics,
        private int $period = 14,
        private float $overbought = 70.0,
        private float $oversold = 30.0,
        ?TrailingStop $trailingStop = null,
    ) {
        parent::__construct($metrics);
        if ($trailingStop) {
            $this->enableTrailingStop($trailingStop);
        }
    }

    public function signal(): string
    {
        // Need enough bars to calculate previous and current RSI
        if ($this->metrics->barCount() <= $this->period + 1) {
            return 'hold';
        }

        $bars = $this->extractBars();

        $currentRsi = $this->metrics->relativeStrengthIndex($this->period);
        $previousMetrics = new AssetMetrics(array_slice($bars, 0, -1));
        $previousRsi = $previousMetrics->relativeStrengthIndex($this->period);

        if ($previousRsi <= $this->oversold && $currentRsi > $this->oversold) {
            return 'buy';
        }
        if ($previousRsi >= $this->overbought && $currentRsi < $this->overbought) {
            return 'sell';
        }
        return 'hold';
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume?:float}>
     */
    private function extractBars(): array
    {
        $reflection = new \ReflectionClass($this->metrics);
        $property = $reflection->getProperty('bars');
        $property->setAccessible(true);
        /** @var array $bars */
        $bars = $property->getValue($this->metrics);
        return $bars;
    }
}
