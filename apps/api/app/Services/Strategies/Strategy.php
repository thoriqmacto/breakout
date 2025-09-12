<?php

namespace App\Services\Strategies;

use App\Services\AssetMetrics;

abstract class Strategy
{
    public function __construct(protected AssetMetrics $metrics)
    {
    }

    private ?TrailingStop $trailingStop = null;

    /**
     * Clone the strategy with updated metrics.
     */
    public function withMetrics(AssetMetrics $metrics): static
    {
        $clone = clone $this;
        $clone->metrics = $metrics;
        return $clone;
    }

    public function enableTrailingStop(TrailingStop $stop): void
    {
        $this->trailingStop = $stop;
    }

    public function trailingStop(): ?TrailingStop
    {
        return $this->trailingStop;
    }

    /**
     * Determine the action to take based on current metrics.
     *
     * @return string One of 'buy', 'sell', or 'hold'
     */
    abstract public function signal(): string;
}
