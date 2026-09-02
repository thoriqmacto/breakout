<?php

namespace App\Services\Strategy;

/**
 * What actually happened after one signal.
 *
 * Two independent questions live here, and keeping them apart is the point:
 *
 *   Did price reach +5% before the initial stop?
 *       A property of the setup. Fixed stop, no trailing, no costs. This is
 *       what the historical probability is computed from, because it is the
 *       question that does not depend on how the position is later managed.
 *
 *   What would the managed trade have returned?
 *       A property of the strategy. Full trailing lifecycle, costs, slippage.
 *       This is what expectancy and profit factor are computed from.
 *
 * Answering the first with the second would make P(+5%) depend on the trail
 * distance, which it does not, and would quietly change every stored
 * probability whenever the profile's trailing parameters were tuned.
 */
final class SignalOutcome
{
    /**
     * @param  array<int, float>  $mfe  horizon in sessions => max favourable excursion, percent
     * @param  array<int, float>  $mae  horizon in sessions => max adverse excursion, percent
     */
    public function __construct(
        public readonly float $entryPrice,
        public readonly ?float $initialStopPrice,
        public readonly ?float $initialRiskPct,
        public readonly int $forwardSessions,
        public readonly array $mfe,
        public readonly array $mae,
        public readonly bool $hitFivePct,
        public readonly ?int $daysToFivePct,
        public readonly bool $hitInitialStop,
        public readonly ?int $daysToInitialStop,
        public readonly bool $hitStopBeforeFivePct,
        public readonly bool $reachedFivePctBeforeStop,
        public readonly bool $trailingActivated,
        public readonly ?string $trailingActivatedAt,
        public readonly ?string $exitDate,
        public readonly ?float $exitPrice,
        public readonly ?string $exitReason,
        public readonly ?float $maxGainBeforeExitPct,
        public readonly ?float $grossReturnPct,
        public readonly ?float $netReturnPct,
        public readonly int $holdSessions,
        public readonly bool $resolved,
    ) {}

    /**
     * Whether the +5%-before-stop question has an answer at all.
     *
     * A signal whose forward data runs out before either level is reached is
     * unresolved, and counting it as a miss would bias every probability
     * downward near the end of the data.
     */
    public function fiveVersusStopResolved(): bool
    {
        return $this->reachedFivePctBeforeStop || $this->hitStopBeforeFivePct;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'entry_price' => round($this->entryPrice, 4),
            'initial_stop_price' => $this->initialStopPrice === null ? null : round($this->initialStopPrice, 4),
            'initial_risk_pct' => $this->initialRiskPct,
            'forward_sessions' => $this->forwardSessions,
            'hit_5pct' => $this->hitFivePct,
            'days_to_5pct' => $this->daysToFivePct,
            'hit_initial_stop' => $this->hitInitialStop,
            'days_to_initial_stop' => $this->daysToInitialStop,
            'hit_stop_before_5pct' => $this->hitStopBeforeFivePct,
            'reached_5pct_before_stop' => $this->reachedFivePctBeforeStop,
            'trailing_activated' => $this->trailingActivated,
            'trailing_activated_at' => $this->trailingActivatedAt,
            'exit_date' => $this->exitDate,
            'exit_price' => $this->exitPrice === null ? null : round($this->exitPrice, 4),
            'exit_reason' => $this->exitReason,
            'max_gain_before_exit_pct' => $this->maxGainBeforeExitPct,
            'gross_return_pct' => $this->grossReturnPct,
            'net_return_pct' => $this->netReturnPct,
            'hold_sessions' => $this->holdSessions,
            'resolved' => $this->resolved,
        ];

        foreach ($this->mfe as $horizon => $value) {
            $out['mfe_'.$horizon.'d'] = $value;
        }

        foreach ($this->mae as $horizon => $value) {
            $out['mae_'.$horizon.'d'] = $value;
        }

        return $out;
    }
}
