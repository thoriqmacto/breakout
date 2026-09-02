<?php

namespace App\Services\Execution;

use App\Services\Strategy\StrategyProfile;

/**
 * The +5% activation / 2% trailing / +3% floor lifecycle, as arithmetic.
 *
 * Before activation the position is managed by the structural stop it was
 * opened with. Once the highest price seen since entry reaches
 * `entry * (1 + activation/100)`, trailing switches on and the stop becomes
 *
 *     effective = max(profit_floor, highest_since_entry * (1 - trail/100))
 *
 * and, from that moment, may never move down. The floor is what makes the
 * activation worth having: a 2% trail off a price only just above +5% would
 * otherwise sit at +2.9%, and one ordinary pullback would give the whole move
 * back. With the floor the same pullback exits around +3% at the price level.
 *
 *     entry 1000, high 1050
 *     trail  1050 * 0.98 = 1029
 *     floor  1000 * 1.03 = 1030
 *     stop   max(1029, 1030) = 1030
 *
 * "Around +3%" is doing real work in that sentence. The floor is a price
 * level, not a guaranteed return: a gap can open below it, the fill can slip,
 * and fees come off whatever is realised. The engine models the first two --
 * a session that opens below the stop exits at the open, not at the stop --
 * and the cost model handles the third.
 *
 * Daily bars cannot say whether the high or the stop came first within one
 * session. Under the conservative assumption the stop is checked against the
 * level in force at the *start* of the session, so a day that both makes a
 * new high and trades through the old stop is an exit, and the new high never
 * gets to raise the stop that would have saved it. That is the pessimistic
 * reading, and it is the default because the optimistic one silently reports
 * trades that may never have happened.
 *
 * Pure PHP. No clock, no database, deterministic.
 */
class TrailingStopEngine
{
    public const EXIT_INITIAL_STOP = 'initial_stop';

    public const EXIT_TRAILING_STOP = 'trailing_stop';

    public const EXIT_GAP_THROUGH_STOP = 'gap_through_stop';

    public const EXIT_TIME = 'max_holding_period';

    public const EXIT_END_OF_DATA = 'end_of_data';

    /**
     * Open a position at a known fill.
     */
    public function open(float $entryPrice, ?float $initialStop, string $entryDate, StrategyProfile $profile): TrailingState
    {
        return new TrailingState(
            entryPrice: $entryPrice,
            highestPriceSinceEntry: $entryPrice,
            trailingActive: false,
            trailingActivatedAt: null,
            trailingActivationPrice: $profile->activationPrice($entryPrice),
            profitFloorPrice: null,
            trailingStopPrice: null,
            initialStopPrice: $initialStop,
            effectiveStopPrice: $initialStop,
            stopUpdatedAt: $entryDate,
            sessionsHeld: 0,
        );
    }

    /**
     * Advance one session.
     *
     * Returns the state after the session and, when the session ended the
     * position, the price and reason. The order of operations is the whole
     * behaviour of this method, so it is written out rather than folded
     * together:
     *
     *   1. The stop in force is the one the session opened with. Nothing this
     *      session does can retroactively have protected it.
     *   2. A session that opens at or below that stop exits at the open. The
     *      stop was never available at its own price.
     *   3. Conservative: a session whose low reaches the stop exits there,
     *      before any new high is allowed to raise it.
     *   4. The high updates the running maximum, which may activate trailing
     *      and may raise the stop -- never lower it.
     *   5. Optimistic: only now is the low checked, against the raised stop.
     *
     * @param  array{high: float, low: float, close: float, open?: float|null}  $bar
     * @return array{state: TrailingState, exited: bool, exit_price: ?float, exit_reason: ?string}
     */
    public function advance(TrailingState $state, array $bar, string $date, StrategyProfile $profile): array
    {
        $high = (float) $bar['high'];
        $low = (float) $bar['low'];
        $open = isset($bar['open']) && $bar['open'] !== null ? (float) $bar['open'] : null;

        $stopInForce = $state->effectiveStopPrice;
        $held = $state->sessionsHeld + 1;

        // 2. Gapped through. Exiting at the stop price here would report a
        //    fill that was never on offer.
        if ($stopInForce !== null && $open !== null && $open <= $stopInForce) {
            return [
                'state' => $this->withSession($state, $held, $date),
                'exited' => true,
                'exit_price' => $open,
                'exit_reason' => self::EXIT_GAP_THROUGH_STOP,
            ];
        }

        // 3. Conservative resolution of the intraday ambiguity.
        if ($profile->conservativeIntraday() && $stopInForce !== null && $low <= $stopInForce) {
            return [
                'state' => $this->withSession($state, $held, $date),
                'exited' => true,
                'exit_price' => $stopInForce,
                'exit_reason' => $state->trailingActive ? self::EXIT_TRAILING_STOP : self::EXIT_INITIAL_STOP,
            ];
        }

        // 4. The high raises the running maximum, and possibly the stop.
        $advanced = $this->applyHigh($state, $high, $date, $profile, $held);

        // 5. Optimistic resolution: the raised stop gets to protect the same
        //    session that raised it.
        if (! $profile->conservativeIntraday()) {
            $stopAfter = $advanced->effectiveStopPrice;

            if ($stopAfter !== null && $low <= $stopAfter) {
                return [
                    'state' => $advanced,
                    'exited' => true,
                    'exit_price' => $stopAfter,
                    'exit_reason' => $advanced->trailingActive ? self::EXIT_TRAILING_STOP : self::EXIT_INITIAL_STOP,
                ];
            }
        }

        if ($held >= $profile->maxHoldingSessions) {
            return [
                'state' => $advanced,
                'exited' => true,
                'exit_price' => (float) $bar['close'],
                'exit_reason' => self::EXIT_TIME,
            ];
        }

        return [
            'state' => $advanced,
            'exited' => false,
            'exit_price' => null,
            'exit_reason' => null,
        ];
    }

    /**
     * Recompute the stop from a new high, without a session boundary.
     *
     * Used by the workspace, where a live position's highest price since
     * entry is known from stored bars and the question is only "where is the
     * stop now". Same arithmetic as `advance` step 4, and the same monotonic
     * guarantee.
     */
    public function applyHigh(TrailingState $state, float $high, string $date, StrategyProfile $profile, ?int $sessionsHeld = null): TrailingState
    {
        $highest = max($state->highestPriceSinceEntry, $high);
        $activated = $state->trailingActive || $highest >= $state->trailingActivationPrice;

        $activatedAt = $state->trailingActivatedAt;

        if ($activated && $activatedAt === null) {
            $activatedAt = $date;
        }

        $profitFloor = $state->profitFloorPrice;
        $trailingStop = $state->trailingStopPrice;
        $effective = $state->effectiveStopPrice;
        $stopUpdatedAt = $state->stopUpdatedAt;

        if ($activated) {
            $profitFloor = $profile->profitFloorPrice($state->entryPrice);
            $trailingStop = $highest * (1.0 - $profile->trailingDistancePct / 100.0);

            $candidate = max($profitFloor, $trailingStop);

            // The guarantee. Once trailing is on the stop is a ratchet, so a
            // lower candidate -- which a falling high cannot produce, but a
            // profile change mid-position could -- is discarded rather than
            // applied.
            $newEffective = $effective === null ? $candidate : max($effective, $candidate);

            if ($effective === null || $newEffective > $effective) {
                $stopUpdatedAt = $date;
            }

            $effective = $newEffective;
        }

        return new TrailingState(
            entryPrice: $state->entryPrice,
            highestPriceSinceEntry: $highest,
            trailingActive: $activated,
            trailingActivatedAt: $activatedAt,
            trailingActivationPrice: $state->trailingActivationPrice,
            profitFloorPrice: $profitFloor,
            trailingStopPrice: $trailingStop,
            initialStopPrice: $state->initialStopPrice,
            effectiveStopPrice: $effective,
            stopUpdatedAt: $stopUpdatedAt,
            sessionsHeld: $sessionsHeld ?? $state->sessionsHeld,
        );
    }

    /**
     * The state as it stands when a session ends the position: the session is
     * counted, but nothing that happened during it raises the stop.
     */
    private function withSession(TrailingState $state, int $sessionsHeld, string $date): TrailingState
    {
        return new TrailingState(
            entryPrice: $state->entryPrice,
            highestPriceSinceEntry: $state->highestPriceSinceEntry,
            trailingActive: $state->trailingActive,
            trailingActivatedAt: $state->trailingActivatedAt,
            trailingActivationPrice: $state->trailingActivationPrice,
            profitFloorPrice: $state->profitFloorPrice,
            trailingStopPrice: $state->trailingStopPrice,
            initialStopPrice: $state->initialStopPrice,
            effectiveStopPrice: $state->effectiveStopPrice,
            stopUpdatedAt: $state->stopUpdatedAt,
            sessionsHeld: $sessionsHeld,
        );
    }
}
