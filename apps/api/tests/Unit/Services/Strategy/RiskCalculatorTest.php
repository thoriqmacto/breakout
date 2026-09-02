<?php

namespace Tests\Unit\Services\Strategy;

use App\Services\Strategy\RiskCalculator;
use PHPUnit\Framework\TestCase;

class RiskCalculatorTest extends TestCase
{
    public function test_compute_uses_atr_stop_when_swing_low_unavailable(): void
    {
        $calc = new RiskCalculator;
        // close=1000, atr14=20, swingLow=null, high55=1300
        // atr-stop = 1000 - 2*20 = 960; tp = 1300 (since 1300 > 1000)
        $result = $calc->compute(close: 1000.0, atr14: 20.0, swingLow: null, high55: 1300.0);

        $this->assertSame(960.0, $result['invalidation_level']);
        $this->assertSame(1300.0, $result['take_profit']);
        // risk=40, reward=300 → R/R=7.5
        $this->assertSame(7.5, $result['risk_reward']);
    }

    public function test_compute_picks_tighter_of_atr_and_swing_low(): void
    {
        $calc = new RiskCalculator;
        // close=1000, atr14=50 → atr-stop=900; swingLow=950 (tighter)
        $result = $calc->compute(close: 1000.0, atr14: 50.0, swingLow: 950.0, high55: 1100.0);

        $this->assertSame(950.0, $result['invalidation_level']);
        // risk=50, reward=100 → R/R=2.0
        $this->assertSame(2.0, $result['risk_reward']);
    }

    public function test_compute_falls_back_to_min_target_pct_when_high55_below_close(): void
    {
        $calc = new RiskCalculator;
        // high55=900 below close=1000 → tp = 1000 * (1 + 0.10) = 1100
        $result = $calc->compute(close: 1000.0, atr14: 20.0, swingLow: 970.0, high55: 900.0);

        $this->assertSame(1100.0, $result['take_profit']);
    }

    public function test_compute_returns_nulls_for_invalid_close(): void
    {
        $calc = new RiskCalculator;
        $result = $calc->compute(close: 0.0, atr14: 20.0, swingLow: 950.0, high55: 1100.0);

        $this->assertNull($result['invalidation_level']);
        $this->assertNull($result['take_profit']);
        $this->assertNull($result['risk_reward']);
    }

    public function test_compute_returns_null_invalidation_when_no_candidates_below_close(): void
    {
        $calc = new RiskCalculator;
        // atr14=null, swingLow=null → no invalidation candidates
        $result = $calc->compute(close: 1000.0, atr14: null, swingLow: null, high55: 1100.0);

        $this->assertNull($result['invalidation_level']);
        $this->assertNull($result['risk_reward']);
        $this->assertSame(1100.0, $result['take_profit']);
    }

    public function test_compute_floors_risk_to_min_pct(): void
    {
        $calc = new RiskCalculator;
        // close=1000, atr14=0.1, swingLow=null → atr-stop=999.8 (very tight)
        // raw risk=0.2 < min(1000*0.005=5), so risk floored to 5; reward=100; R/R=20
        $result = $calc->compute(close: 1000.0, atr14: 0.1, swingLow: null, high55: 1100.0);

        $this->assertSame(20.0, $result['risk_reward']);
    }

    public function test_risk_notes_reference_inputs(): void
    {
        $calc = new RiskCalculator;
        $result = $calc->compute(close: 1000.0, atr14: 20.0, swingLow: 970.0, high55: 1100.0);

        $this->assertStringContainsString('ATR14=20', $result['risk_notes']);
        $this->assertStringContainsString('swing-low=970', $result['risk_notes']);
        $this->assertStringContainsString('high55=1100', $result['risk_notes']);
    }

    /**
     * The production failure of 2026-09-02, with MLPT's real numbers.
     *
     * MLPT split roughly 1:27 on 2026-05-21 (close 20,475 -> 770) and the
     * stored bars are unadjusted, so the 55-*week* high still reaches across
     * the split to a pre-split 257,875. Used as a take-profit against a close
     * of 1,260 that is a 205x target and an R/R of 12,830.75, which does not
     * fit `watchlist_scores.risk_reward` (decimal(8,4), max 9999.9999). The
     * insert threw and took the whole watchlist step down with it.
     *
     * A target 205x the close is not resistance, it is a corporate action
     * showing through, so the honest answer is to decline it and say so.
     */
    public function test_an_implausible_high55_is_not_used_as_a_target(): void
    {
        $calc = new RiskCalculator;

        $result = $calc->compute(
            close: 1260.0,
            atr14: 100.0,
            swingLow: 1240.0,
            high55: 257875.0,
        );

        // Falls back to the minimum-target rule rather than the bad high.
        $this->assertSame(1386.0, $result['take_profit']);
        $this->assertSame(1240.0, $result['invalidation_level']);

        // risk = max(1260-1240, 0.5% of 1260) = 20; reward = 126 -> 6.3
        $this->assertSame(6.3, $result['risk_reward']);

        $this->assertStringContainsString('high55 rejected', $result['risk_notes']);
        $this->assertStringContainsString('257875', $result['risk_notes']);
    }

    /** A real drawdown is still a real resistance level. */
    public function test_a_plausible_high55_is_still_used(): void
    {
        $calc = new RiskCalculator;

        // 5.3x, the largest genuine drawdown in the current universe (TPIA).
        $result = $calc->compute(close: 1000.0, atr14: 20.0, swingLow: null, high55: 5300.0);

        $this->assertSame(5300.0, $result['take_profit']);
        $this->assertStringNotContainsString('rejected', $result['risk_notes']);
    }

    /**
     * The ratio can never exceed what the column can store, whatever the
     * inputs. Belt and braces behind the target check: a tight stop against a
     * just-plausible target is the other way to a very large ratio.
     */
    public function test_the_ratio_is_bounded_to_what_the_column_can_hold(): void
    {
        $calc = new RiskCalculator;

        $result = $calc->compute(
            close: 1000.0,
            atr14: null,
            swingLow: 999.999,
            high55: 9000.0,
            minTargetPct: 0.10,
        );

        $this->assertNotNull($result['risk_reward']);
        $this->assertLessThanOrEqual(RiskCalculator::MAX_RISK_REWARD, $result['risk_reward']);
    }
}
