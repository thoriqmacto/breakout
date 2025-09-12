<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;
use App\Services\IdxTicks;

class TrailingStop
{
    private function __construct(
        private string $type,
        private float $value,
        private int $period = 14
    ) {}

    public static function percent(float $percent): self
    {
        return new self('percent', $percent);
    }

    public static function atr(float $multiple, int $period = 14): self
    {
        return new self('atr', $multiple, $period);
    }

    public function level(float $extremePrice, AssetMetrics $metrics, string $direction = 'long'): float
    {
        if ($this->type === 'percent') {
            $delta = $extremePrice * $this->value;
        } else {
            $delta = $this->value * $metrics->atr($this->period);
        }

        $ticks = IdxTicks::toTicks($delta, $extremePrice);
        $offset = IdxTicks::fromTicks($ticks, $extremePrice);

        if ($direction === 'long') {
            return $extremePrice - $offset;
        }

        return $extremePrice + $offset;
    }
}
