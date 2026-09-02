<?php

namespace App\Services\Execution;

/**
 * Everything the profit lifecycle needs to know about one open position.
 *
 * Immutable: each session produces a new state rather than mutating the old
 * one, which is what makes the monotonic-stop guarantee checkable. A stop
 * that can only be raised is easy to state and easy to violate accidentally
 * when the same object is written to from three places.
 *
 * The same object serves the live workspace and the backtester, so a
 * simulated trade and a real holding are managed by identical arithmetic.
 * Any divergence between the two would make the historical statistics
 * describe a strategy nobody is running.
 */
final class TrailingState
{
    public function __construct(
        public readonly float $entryPrice,
        public readonly float $highestPriceSinceEntry,
        public readonly bool $trailingActive,
        public readonly ?string $trailingActivatedAt,
        public readonly float $trailingActivationPrice,
        public readonly ?float $profitFloorPrice,
        public readonly ?float $trailingStopPrice,
        public readonly ?float $initialStopPrice,
        public readonly ?float $effectiveStopPrice,
        public readonly ?string $stopUpdatedAt,
        public readonly int $sessionsHeld = 0,
    ) {}

    /**
     * Gain from entry to the highest price seen, as a percentage.
     */
    public function maxGainPct(): float
    {
        if ($this->entryPrice <= 0) {
            return 0.0;
        }

        return round((($this->highestPriceSinceEntry - $this->entryPrice) / $this->entryPrice) * 100.0, 4);
    }

    /**
     * Where the effective stop sits relative to entry, as a percentage.
     *
     * Positive once trailing has activated: that is the profit the stop is
     * holding at the price level, before fees and before any slippage on the
     * way out. Negative while the initial stop is still in force.
     */
    public function lockedProfitPct(): ?float
    {
        if ($this->effectiveStopPrice === null || $this->entryPrice <= 0) {
            return null;
        }

        return round((($this->effectiveStopPrice - $this->entryPrice) / $this->entryPrice) * 100.0, 4);
    }

    /**
     * How far the price still has to travel before trailing switches on.
     */
    public function distanceToActivationPct(float $currentPrice): ?float
    {
        if ($this->trailingActive || $currentPrice <= 0) {
            return null;
        }

        return round((($this->trailingActivationPrice - $currentPrice) / $currentPrice) * 100.0, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entry_price' => round($this->entryPrice, 4),
            'highest_price_since_entry' => round($this->highestPriceSinceEntry, 4),
            'trailing_active' => $this->trailingActive,
            'trailing_activated_at' => $this->trailingActivatedAt,
            'trailing_activation_price' => round($this->trailingActivationPrice, 4),
            'profit_floor_price' => $this->profitFloorPrice === null ? null : round($this->profitFloorPrice, 4),
            'trailing_stop_price' => $this->trailingStopPrice === null ? null : round($this->trailingStopPrice, 4),
            'initial_stop_price' => $this->initialStopPrice === null ? null : round($this->initialStopPrice, 4),
            'effective_stop_price' => $this->effectiveStopPrice === null ? null : round($this->effectiveStopPrice, 4),
            'stop_updated_at' => $this->stopUpdatedAt,
            'sessions_held' => $this->sessionsHeld,
            'max_gain_pct' => $this->maxGainPct(),
            'locked_profit_pct' => $this->lockedProfitPct(),
        ];
    }
}
