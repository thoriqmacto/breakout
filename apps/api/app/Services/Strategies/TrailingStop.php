<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

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
            if ($direction === 'long') {
                return $extremePrice * (1 - $this->value);
            }
            return $extremePrice * (1 + $this->value);
        }

        $atr = $metrics->atr($this->period);
        if ($direction === 'long') {
            return $extremePrice - $this->value * $atr;
        }

        return $extremePrice + $this->value * $atr;
    }
}
