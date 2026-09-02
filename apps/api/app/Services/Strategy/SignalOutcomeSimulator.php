<?php

namespace App\Services\Strategy;

use App\Services\Execution\TrailingStopEngine;

/**
 * Replays what happened after a fill, using only bars dated after it.
 *
 * The simulator is deliberately ignorant of everything that produced the
 * signal. It receives a fill price, a stop, and the forward bars; it cannot
 * see the setup, the score, or any data before the entry, so there is no path
 * by which a forward outcome could influence the signal that generated it.
 * The leak this design prevents is the opposite one -- signal generation
 * reading forward bars -- and that is prevented by the caller only ever
 * handing over bars strictly after the entry session.
 *
 * Two passes, because two different questions are being asked.
 *
 *   The probability pass runs a fixed initial stop with no trailing and no
 *   costs, and answers "did price reach +5% before the stop". That is a
 *   property of the setup, so it must not move when the trailing parameters
 *   are tuned.
 *
 *   The lifecycle pass runs the real TrailingStopEngine and the real cost
 *   model, and answers "what would this trade have returned". Same engine the
 *   live workspace uses, so a simulated trade and a held position are managed
 *   by identical arithmetic.
 *
 * Both passes inherit the profile's intraday assumption. Under the
 * conservative default, a session whose range contains both the +5% level and
 * the stop is read as the stop first -- daily bars cannot say which came
 * first, and assuming the favourable order is how a backtest reports trades
 * that may never have happened.
 */
class SignalOutcomeSimulator
{
    public function __construct(private readonly TrailingStopEngine $engine) {}

    /**
     * @param  array<int, array{date: string, open: ?float, high: float, low: float, close: float}>  $forwardBars
     *                                                                                                             Sessions strictly after the entry session, ascending.
     */
    public function simulate(
        float $entryPrice,
        ?float $initialStop,
        string $entryDate,
        array $forwardBars,
        StrategyProfile $profile,
        ?TradingCostModel $costs = null,
    ): SignalOutcome {
        $costs ??= new TradingCostModel($profile);

        $activationPrice = $profile->activationPrice($entryPrice);
        $initialRiskPct = ($initialStop !== null && $entryPrice > 0)
            ? round((($entryPrice - $initialStop) / $entryPrice) * 100.0, 4)
            : null;

        $probability = $this->probabilityPass($entryPrice, $initialStop, $activationPrice, $forwardBars, $profile);
        $lifecycle = $this->lifecyclePass($entryPrice, $initialStop, $entryDate, $forwardBars, $profile, $costs);

        return new SignalOutcome(
            entryPrice: $entryPrice,
            initialStopPrice: $initialStop,
            initialRiskPct: $initialRiskPct,
            forwardSessions: count($forwardBars),
            mfe: $this->excursions($entryPrice, $forwardBars, $profile->outcomeHorizons, favourable: true),
            mae: $this->excursions($entryPrice, $forwardBars, $profile->outcomeHorizons, favourable: false),
            hitFivePct: $probability['hit_5pct'],
            daysToFivePct: $probability['days_to_5pct'],
            hitInitialStop: $probability['hit_stop'],
            daysToInitialStop: $probability['days_to_stop'],
            hitStopBeforeFivePct: $probability['stop_first'],
            reachedFivePctBeforeStop: $probability['five_first'],
            trailingActivated: $lifecycle['trailing_activated'],
            trailingActivatedAt: $lifecycle['trailing_activated_at'],
            exitDate: $lifecycle['exit_date'],
            exitPrice: $lifecycle['exit_price'],
            exitReason: $lifecycle['exit_reason'],
            maxGainBeforeExitPct: $lifecycle['max_gain_pct'],
            grossReturnPct: $lifecycle['gross_return_pct'],
            netReturnPct: $lifecycle['net_return_pct'],
            holdSessions: $lifecycle['hold_sessions'],
            resolved: $lifecycle['exit_reason'] !== null && $lifecycle['exit_reason'] !== TrailingStopEngine::EXIT_END_OF_DATA,
        );
    }

    /**
     * Did price reach +5% before the initial stop?
     *
     * Fixed stop, no trailing, no costs. Nothing in this pass may depend on
     * how the position would later be managed.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array{hit_5pct: bool, days_to_5pct: ?int, hit_stop: bool, days_to_stop: ?int, five_first: bool, stop_first: bool}
     */
    private function probabilityPass(
        float $entryPrice,
        ?float $initialStop,
        float $activationPrice,
        array $bars,
        StrategyProfile $profile,
    ): array {
        $hitFive = false;
        $daysToFive = null;
        $hitStop = false;
        $daysToStop = null;
        $fiveFirst = false;
        $stopFirst = false;

        foreach ($bars as $index => $bar) {
            $session = $index + 1;
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $open = isset($bar['open']) && $bar['open'] !== null ? (float) $bar['open'] : null;

            $reachedFiveHere = $high >= $activationPrice;
            $reachedStopHere = $initialStop !== null && ($low <= $initialStop || ($open !== null && $open <= $initialStop));

            if ($reachedFiveHere && $daysToFive === null) {
                $hitFive = true;
                $daysToFive = $session;
            }

            if ($reachedStopHere && $daysToStop === null) {
                $hitStop = true;
                $daysToStop = $session;
            }

            if (! $fiveFirst && ! $stopFirst) {
                if ($reachedFiveHere && $reachedStopHere) {
                    // Both inside one daily range. The sequence is unknowable
                    // from this data, so the assumption decides.
                    if ($profile->conservativeIntraday()) {
                        $stopFirst = true;
                    } else {
                        $fiveFirst = true;
                    }
                } elseif ($reachedFiveHere) {
                    $fiveFirst = true;
                } elseif ($reachedStopHere) {
                    $stopFirst = true;
                }
            }

            // Both first-touch dates are recorded for reporting even after
            // the ordering is settled, so the walk continues to the end of
            // the forward window rather than stopping at the decision.
            if ($daysToFive !== null && $daysToStop !== null) {
                break;
            }
        }

        return [
            'hit_5pct' => $hitFive,
            'days_to_5pct' => $daysToFive,
            'hit_stop' => $hitStop,
            'days_to_stop' => $daysToStop,
            'five_first' => $fiveFirst,
            'stop_first' => $stopFirst,
        ];
    }

    /**
     * The managed trade: trailing lifecycle plus costs.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<string, mixed>
     */
    private function lifecyclePass(
        float $entryPrice,
        ?float $initialStop,
        string $entryDate,
        array $bars,
        StrategyProfile $profile,
        TradingCostModel $costs,
    ): array {
        $state = $this->engine->open($entryPrice, $initialStop, $entryDate, $profile);

        $exitDate = null;
        $exitPrice = null;
        $exitReason = null;
        $held = 0;

        foreach ($bars as $bar) {
            $result = $this->engine->advance($state, [
                'open' => $bar['open'] ?? null,
                'high' => (float) $bar['high'],
                'low' => (float) $bar['low'],
                'close' => (float) $bar['close'],
            ], (string) $bar['date'], $profile);

            $state = $result['state'];
            $held = $state->sessionsHeld;

            if ($result['exited']) {
                $exitDate = (string) $bar['date'];
                $exitPrice = $result['exit_price'];
                $exitReason = $result['exit_reason'];

                break;
            }
        }

        // Ran out of data before any exit condition. Marked as such rather
        // than closed at the last close, so an unfinished trade cannot be
        // counted as a completed one.
        if ($exitReason === null && $bars !== []) {
            $last = $bars[array_key_last($bars)];
            $exitDate = (string) $last['date'];
            $exitPrice = (float) $last['close'];
            $exitReason = TrailingStopEngine::EXIT_END_OF_DATA;
        }

        return [
            'trailing_activated' => $state->trailingActive,
            'trailing_activated_at' => $state->trailingActivatedAt,
            'exit_date' => $exitDate,
            'exit_price' => $exitPrice,
            'exit_reason' => $exitReason,
            'max_gain_pct' => $state->maxGainPct(),
            'gross_return_pct' => $exitPrice === null ? null : $costs->grossReturnPct($entryPrice, $exitPrice),
            'net_return_pct' => $exitPrice === null ? null : $costs->netReturnPct($entryPrice, $exitPrice),
            'hold_sessions' => $held,
        ];
    }

    /**
     * Maximum favourable or adverse excursion over each horizon, in percent.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @param  array<int, int>  $horizons
     * @return array<int, float>
     */
    private function excursions(float $entryPrice, array $bars, array $horizons, bool $favourable): array
    {
        $out = [];

        if ($entryPrice <= 0) {
            return $out;
        }

        foreach ($horizons as $horizon) {
            $slice = array_slice($bars, 0, $horizon);

            if ($slice === []) {
                continue;
            }

            $extreme = null;

            foreach ($slice as $bar) {
                $value = $favourable ? (float) $bar['high'] : (float) $bar['low'];
                $extreme = $extreme === null
                    ? $value
                    : ($favourable ? max($extreme, $value) : min($extreme, $value));
            }

            $out[$horizon] = round((($extreme - $entryPrice) / $entryPrice) * 100.0, 4);
        }

        return $out;
    }
}
