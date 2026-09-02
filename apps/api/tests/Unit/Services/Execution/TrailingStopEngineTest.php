<?php

namespace Tests\Unit\Services\Execution;

use App\Services\Execution\TrailingState;
use App\Services\Execution\TrailingStopEngine;
use App\Services\Strategy\StrategyProfile;
use Tests\TestCase;

/**
 * The profit lifecycle, asserted as arithmetic.
 *
 * These are the rules a position is actually managed by, so they are tested
 * on the engine itself rather than through a backtest that could mask an
 * error in aggregate. The monotonic-stop guarantee in particular is the kind
 * of property that only ever fails on one path.
 */
class TrailingStopEngineTest extends TestCase
{
    private TrailingStopEngine $engine;

    private StrategyProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new TrailingStopEngine;
        $this->profile = StrategyProfile::fromArray([
            'version' => 'test',
            'trail_activation_gain_pct' => 5.0,
            'trailing_distance_pct' => 2.0,
            'minimum_locked_profit_pct' => 3.0,
            'max_holding_sessions' => 100,
            'intraday_assumption' => StrategyProfile::INTRADAY_CONSERVATIVE,
        ]);
    }

    /**
     * @param  array<string, float>  $bar
     * @return array{state: TrailingState, exited: bool, exit_price: ?float, exit_reason: ?string}
     */
    private function step(TrailingState $state, array $bar, string $date = '2026-01-02'): array
    {
        return $this->engine->advance($state, $bar, $date, $this->profile);
    }

    public function test_trailing_is_inactive_below_the_activation_level(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $result = $this->step($state, ['open' => 1000.0, 'high' => 1040.0, 'low' => 995.0, 'close' => 1030.0]);

        $this->assertFalse($result['state']->trailingActive);
        $this->assertFalse($result['exited']);
        // Still the structural stop: nothing has activated the trail.
        $this->assertEqualsWithDelta(960.0, $result['state']->effectiveStopPrice, 0.0001);
        $this->assertNull($result['state']->profitFloorPrice);
    }

    public function test_reaching_five_percent_activates_trailing(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $result = $this->step($state, ['open' => 1000.0, 'high' => 1050.0, 'low' => 999.0, 'close' => 1045.0]);

        $this->assertTrue($result['state']->trailingActive);
        $this->assertSame('2026-01-02', $result['state']->trailingActivatedAt);
        $this->assertEqualsWithDelta(1050.0, $result['state']->trailingActivationPrice, 0.0001);
    }

    /**
     * The worked example from the specification. At exactly +5% the 2% trail
     * sits below the floor, so the floor is what holds.
     */
    public function test_the_profit_floor_wins_immediately_after_activation(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $result = $this->step($state, ['open' => 1000.0, 'high' => 1050.0, 'low' => 999.0, 'close' => 1045.0]);
        $after = $result['state'];

        $this->assertEqualsWithDelta(1029.0, $after->trailingStopPrice, 0.0001);
        $this->assertEqualsWithDelta(1030.0, $after->profitFloorPrice, 0.0001);
        $this->assertEqualsWithDelta(1030.0, $after->effectiveStopPrice, 0.0001);
        $this->assertEqualsWithDelta(3.0, $after->lockedProfitPct(), 0.0001);
    }

    public function test_the_trailing_stop_takes_over_once_the_move_extends(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);
        $state = $this->step($state, ['open' => 1000.0, 'high' => 1050.0, 'low' => 999.0, 'close' => 1045.0])['state'];

        // 1100 * 0.98 = 1078, now above the 1030 floor.
        $state = $this->step($state, ['open' => 1048.0, 'high' => 1100.0, 'low' => 1040.0, 'close' => 1090.0], '2026-01-05')['state'];

        $this->assertEqualsWithDelta(1078.0, $state->effectiveStopPrice, 0.0001);
        $this->assertEqualsWithDelta(1100.0, $state->highestPriceSinceEntry, 0.0001);
    }

    public function test_the_effective_stop_never_moves_down(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);
        $state = $this->step($state, ['open' => 1000.0, 'high' => 1100.0, 'low' => 999.0, 'close' => 1090.0])['state'];

        $peak = $state->effectiveStopPrice;
        $this->assertEqualsWithDelta(1078.0, $peak, 0.0001);

        // Three sessions that each make a lower high. The stop is a ratchet:
        // a falling high may not lower it.
        foreach ([['high' => 1090.0, 'low' => 1080.0], ['high' => 1085.0, 'low' => 1079.0], ['high' => 1082.0, 'low' => 1078.5]] as $index => $bar) {
            $result = $this->step($state, [
                'open' => 1081.0,
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => 1081.0,
            ], sprintf('2026-01-%02d', 6 + $index));

            $state = $result['state'];

            $this->assertGreaterThanOrEqual(
                $peak,
                $state->effectiveStopPrice,
                'The trailing stop moved down, which it may never do.'
            );
        }

        $this->assertEqualsWithDelta($peak, $state->effectiveStopPrice, 0.0001);
    }

    public function test_a_session_opening_below_the_stop_exits_at_the_open(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);
        $state = $this->step($state, ['open' => 1000.0, 'high' => 1100.0, 'low' => 999.0, 'close' => 1090.0])['state'];

        // Stop is 1078. The market gaps to 1000 and never offers it.
        $result = $this->step($state, ['open' => 1000.0, 'high' => 1010.0, 'low' => 980.0, 'close' => 990.0], '2026-01-06');

        $this->assertTrue($result['exited']);
        $this->assertSame(TrailingStopEngine::EXIT_GAP_THROUGH_STOP, $result['exit_reason']);
        $this->assertEqualsWithDelta(1000.0, $result['exit_price'], 0.0001);
    }

    public function test_the_initial_stop_exits_before_activation(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $result = $this->step($state, ['open' => 995.0, 'high' => 1005.0, 'low' => 950.0, 'close' => 955.0]);

        $this->assertTrue($result['exited']);
        $this->assertSame(TrailingStopEngine::EXIT_INITIAL_STOP, $result['exit_reason']);
        $this->assertEqualsWithDelta(960.0, $result['exit_price'], 0.0001);
    }

    /**
     * The daily-candle ambiguity, and the reason the default is conservative.
     *
     * One session's range contains both a new high that would have raised the
     * stop to 1078 and a low that reaches the stop already in force at 960.
     * Daily data cannot say which came first.
     */
    public function test_the_conservative_assumption_resolves_intraday_ambiguity_against_the_trade(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $ambiguous = ['open' => 1000.0, 'high' => 1100.0, 'low' => 955.0, 'close' => 1050.0];

        $conservative = $this->engine->advance($state, $ambiguous, '2026-01-02', $this->profile);

        $this->assertTrue($conservative['exited']);
        $this->assertSame(TrailingStopEngine::EXIT_INITIAL_STOP, $conservative['exit_reason']);
        $this->assertEqualsWithDelta(960.0, $conservative['exit_price'], 0.0001);

        $optimistic = $this->engine->advance(
            $state,
            $ambiguous,
            '2026-01-02',
            $this->profile->withOverrides(['intraday_assumption' => StrategyProfile::INTRADAY_OPTIMISTIC]),
        );

        // Same bar, opposite reading: the high raises the stop to 1078 first,
        // and the low then takes the trade out there instead.
        $this->assertTrue($optimistic['exited']);
        $this->assertSame(TrailingStopEngine::EXIT_TRAILING_STOP, $optimistic['exit_reason']);
        $this->assertEqualsWithDelta(1078.0, $optimistic['exit_price'], 0.0001);
    }

    public function test_a_position_is_closed_once_it_reaches_the_holding_limit(): void
    {
        $profile = $this->profile->withOverrides(['max_holding_sessions' => 2]);
        $state = $this->engine->open(1000.0, 900.0, '2026-01-01', $profile);

        $first = $this->engine->advance($state, ['open' => 1000.0, 'high' => 1010.0, 'low' => 995.0, 'close' => 1005.0], '2026-01-02', $profile);
        $this->assertFalse($first['exited']);

        $second = $this->engine->advance($first['state'], ['open' => 1005.0, 'high' => 1012.0, 'low' => 1000.0, 'close' => 1008.0], '2026-01-05', $profile);

        $this->assertTrue($second['exited']);
        $this->assertSame(TrailingStopEngine::EXIT_TIME, $second['exit_reason']);
        $this->assertEqualsWithDelta(1008.0, $second['exit_price'], 0.0001);
    }

    public function test_distance_to_activation_is_reported_until_it_activates(): void
    {
        $state = $this->engine->open(1000.0, 960.0, '2026-01-01', $this->profile);

        $this->assertEqualsWithDelta(5.0, $state->distanceToActivationPct(1000.0), 0.0001);

        $active = $this->step($state, ['open' => 1000.0, 'high' => 1060.0, 'low' => 999.0, 'close' => 1055.0])['state'];

        $this->assertNull($active->distanceToActivationPct(1055.0));
    }
}
