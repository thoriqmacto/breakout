<?php

namespace Tests\Unit\Services\Strategy;

use App\Services\Execution\TrailingStopEngine;
use App\Services\Strategy\SignalOutcomeSimulator;
use App\Services\Strategy\StrategyProfile;
use App\Services\Strategy\TradingCostModel;
use Tests\TestCase;

/**
 * The forward-outcome engine, on bars small enough to check by hand.
 *
 * The +5%-before-stop question is the one the historical probability is built
 * from, so its edge cases -- same-session ambiguity, unresolved trades,
 * independence from the trailing parameters -- are asserted individually
 * rather than inferred from an aggregate that could hide any of them.
 */
class SignalOutcomeSimulatorTest extends TestCase
{
    private SignalOutcomeSimulator $simulator;

    private StrategyProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulator = new SignalOutcomeSimulator(new TrailingStopEngine);
        $this->profile = StrategyProfile::fromArray([
            'version' => 'test',
            'trail_activation_gain_pct' => 5.0,
            'trailing_distance_pct' => 2.0,
            'minimum_locked_profit_pct' => 3.0,
            'outcome_horizons' => [1, 3, 5],
            'max_holding_sessions' => 50,
            'intraday_assumption' => StrategyProfile::INTRADAY_CONSERVATIVE,
            'costs' => ['buy_fee_pct' => 0.0, 'sell_fee_pct' => 0.0, 'slippage_pct' => 0.0, 'round_to_tick' => false],
        ]);
    }

    /**
     * @param  array<int, array{0: float, 1: float, 2: float, 3: float}>  $rows  open, high, low, close
     * @return array<int, array<string, mixed>>
     */
    private function bars(array $rows): array
    {
        $out = [];

        foreach ($rows as $index => [$open, $high, $low, $close]) {
            $out[] = [
                'date' => sprintf('2026-02-%02d', $index + 2),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        }

        return $out;
    }

    public function test_it_records_reaching_five_percent_before_the_stop(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1020.0, 990.0, 1015.0],
            [1015.0, 1055.0, 1010.0, 1050.0],
        ]), $this->profile);

        $this->assertTrue($outcome->hitFivePct);
        $this->assertSame(2, $outcome->daysToFivePct);
        $this->assertTrue($outcome->reachedFivePctBeforeStop);
        $this->assertFalse($outcome->hitStopBeforeFivePct);
        $this->assertTrue($outcome->fiveVersusStopResolved());
    }

    public function test_it_records_the_stop_landing_first(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1010.0, 950.0, 955.0],
            [955.0, 1060.0, 950.0, 1055.0],
        ]), $this->profile);

        $this->assertTrue($outcome->hitStopBeforeFivePct);
        $this->assertFalse($outcome->reachedFivePctBeforeStop);
        // +5% was reached later, and that is recorded without changing the
        // ordering answer.
        $this->assertTrue($outcome->hitFivePct);
        $this->assertSame(2, $outcome->daysToFivePct);
        $this->assertSame(1, $outcome->daysToInitialStop);
    }

    /**
     * One session's range contains both levels. Which came first is not in
     * the data, and the conservative default answers against the trade.
     */
    public function test_same_session_ambiguity_resolves_conservatively(): void
    {
        $bars = $this->bars([[1000.0, 1060.0, 950.0, 1000.0]]);

        $conservative = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $bars, $this->profile);

        $this->assertTrue($conservative->hitStopBeforeFivePct);
        $this->assertFalse($conservative->reachedFivePctBeforeStop);

        $optimistic = $this->simulator->simulate(
            1000.0,
            960.0,
            '2026-02-01',
            $bars,
            $this->profile->withOverrides(['intraday_assumption' => StrategyProfile::INTRADAY_OPTIMISTIC]),
        );

        $this->assertTrue($optimistic->reachedFivePctBeforeStop);
        $this->assertFalse($optimistic->hitStopBeforeFivePct);
    }

    /**
     * A signal whose forward data ends before either level is touched has no
     * answer. Counting it as a miss would bias every probability downward at
     * the recent end of the data, which is exactly where the sample is
     * thinnest and the reader is paying most attention.
     */
    public function test_an_untouched_trade_is_unresolved_rather_than_a_miss(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1010.0, 990.0, 1005.0],
            [1005.0, 1015.0, 995.0, 1010.0],
        ]), $this->profile);

        $this->assertFalse($outcome->reachedFivePctBeforeStop);
        $this->assertFalse($outcome->hitStopBeforeFivePct);
        $this->assertFalse($outcome->fiveVersusStopResolved());
    }

    /**
     * The probability question is a property of the setup, so tuning the
     * trailing parameters must not move it. If it did, every stored
     * probability would silently change with the profile.
     */
    public function test_the_probability_answer_does_not_depend_on_trailing_parameters(): void
    {
        $bars = $this->bars([
            [1000.0, 1020.0, 990.0, 1015.0],
            [1015.0, 1055.0, 1010.0, 1050.0],
            [1050.0, 1060.0, 1000.0, 1005.0],
        ]);

        $tight = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $bars, $this->profile->withOverrides([
            'trailing_distance_pct' => 0.5,
        ]));

        $loose = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $bars, $this->profile->withOverrides([
            'trailing_distance_pct' => 8.0,
        ]));

        $this->assertSame($tight->reachedFivePctBeforeStop, $loose->reachedFivePctBeforeStop);
        $this->assertSame($tight->daysToFivePct, $loose->daysToFivePct);

        // The managed trade, by contrast, is expected to differ.
        $this->assertNotSame($tight->exitPrice, $loose->exitPrice);
    }

    public function test_it_measures_excursions_over_each_horizon(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 900.0, '2026-02-01', $this->bars([
            [1000.0, 1020.0, 980.0, 1010.0],
            [1010.0, 1040.0, 960.0, 1000.0],
            [1000.0, 1030.0, 940.0, 950.0],
        ]), $this->profile);

        $this->assertEqualsWithDelta(2.0, $outcome->mfe[1], 0.0001);
        $this->assertEqualsWithDelta(4.0, $outcome->mfe[3], 0.0001);
        $this->assertEqualsWithDelta(-2.0, $outcome->mae[1], 0.0001);
        $this->assertEqualsWithDelta(-6.0, $outcome->mae[3], 0.0001);
    }

    public function test_the_managed_trade_exits_on_the_trailing_floor(): void
    {
        // Session 1 reaches +5%, activating the trail with a 1030 floor.
        // Session 2 trades down through it.
        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1050.0, 999.0, 1045.0],
            [1045.0, 1046.0, 1020.0, 1025.0],
        ]), $this->profile);

        $this->assertTrue($outcome->trailingActivated);
        $this->assertSame(TrailingStopEngine::EXIT_TRAILING_STOP, $outcome->exitReason);
        $this->assertEqualsWithDelta(1030.0, $outcome->exitPrice, 0.0001);
        $this->assertEqualsWithDelta(3.0, $outcome->grossReturnPct, 0.0001);
        $this->assertTrue($outcome->resolved);
    }

    /**
     * The floor is a price level, not a guaranteed return. With realistic
     * costs the same +3% exit nets materially less.
     */
    public function test_costs_reduce_the_net_return_below_the_price_level_gain(): void
    {
        $withCosts = $this->profile->withOverrides([
            'costs' => ['buy_fee_pct' => 0.15, 'sell_fee_pct' => 0.25, 'slippage_pct' => 0.10, 'round_to_tick' => false],
        ]);

        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1050.0, 999.0, 1045.0],
            [1045.0, 1046.0, 1020.0, 1025.0],
        ]), $withCosts, new TradingCostModel($withCosts));

        $this->assertEqualsWithDelta(3.0, $outcome->grossReturnPct, 0.0001);
        $this->assertLessThan($outcome->grossReturnPct, $outcome->netReturnPct);
        // Round trip is roughly 0.4% brokerage plus 0.2% slippage.
        $this->assertEqualsWithDelta(2.4, $outcome->netReturnPct, 0.15);
    }

    public function test_a_gap_below_the_stop_exits_at_the_open(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 960.0, '2026-02-01', $this->bars([
            [1000.0, 1010.0, 995.0, 1005.0],
            [900.0, 910.0, 880.0, 890.0],
        ]), $this->profile);

        $this->assertSame(TrailingStopEngine::EXIT_GAP_THROUGH_STOP, $outcome->exitReason);
        $this->assertEqualsWithDelta(900.0, $outcome->exitPrice, 0.0001);
        $this->assertEqualsWithDelta(-10.0, $outcome->grossReturnPct, 0.0001);
    }

    public function test_a_trade_still_open_at_the_end_of_the_data_is_not_counted_as_finished(): void
    {
        $outcome = $this->simulator->simulate(1000.0, 900.0, '2026-02-01', $this->bars([
            [1000.0, 1010.0, 990.0, 1005.0],
        ]), $this->profile);

        $this->assertSame(TrailingStopEngine::EXIT_END_OF_DATA, $outcome->exitReason);
        $this->assertFalse($outcome->resolved);
    }
}
