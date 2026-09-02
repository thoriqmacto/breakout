<?php

namespace Tests\Unit\Services\Strategy;

use App\Services\Strategy\BrokerFlowAnalyzer;
use App\Services\Strategy\BrokerRegime;
use App\Services\Strategy\PositionAction;
use App\Services\Strategy\StrategyProfile;
use Tests\TestCase;

/**
 * The broker model, tested on the property it exists to fix.
 *
 * v1 collapsed the four windows into one length-weighted average, which
 * answers "how much net buying" and cannot answer "for how long". The
 * distinction is the entire point of reading them separately, so it gets its
 * own test rather than being inferred from a score.
 */
class BrokerFlowAnalyzerTest extends TestCase
{
    private BrokerFlowAnalyzer $analyzer;

    private StrategyProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new BrokerFlowAnalyzer;
        $this->profile = StrategyProfile::fromArray([
            'version' => 'test',
            'broker_windows' => [3, 5, 10, 20],
            'broker_regime_windows' => [5, 10, 20],
            'broker_flow_epsilon' => 0.005,
            'broker_strong_top3_norm' => 0.05,
        ]);
    }

    /**
     * @param  array<int, float>  $netByWindow
     * @param  array<int, float>  $top3ByWindow
     * @return array<int, array<string, mixed>>
     */
    private function rollups(array $netByWindow, array $top3ByWindow = []): array
    {
        $out = [];

        foreach ($netByWindow as $days => $net) {
            $out[$days] = [
                'window_days' => $days,
                'avg_net_norm' => $net,
                'top3_net_norm' => $top3ByWindow[$days] ?? $net * 4,
                'accdist_score' => $net > 0 ? 1 : ($net < 0 ? -1 : 0),
                'broker_count' => 20,
                'value' => 50_000_000_000.0,
                'covered_days' => $days,
            ];
        }

        return $out;
    }

    public function test_persistent_flow_across_every_window_is_strong_accumulation(): void
    {
        $flow = $this->analyzer->analyze(
            $this->rollups([3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02], [3 => 0.06, 5 => 0.06, 10 => 0.06, 20 => 0.06]),
            $this->profile,
        );

        $this->assertSame(BrokerRegime::STRONG_ACCUMULATION, $flow->regime);
        $this->assertSame(4, $flow->positiveWindows);
        $this->assertSame(4, $flow->availableWindows);
        $this->assertEqualsWithDelta(1.0, $flow->persistenceRatio, 0.0001);
    }

    /**
     * The failure v1 could not express. One enormous three-day figure against
     * three negative medium-term windows: a length-weighted average of these
     * five numbers is comfortably positive, and the stock is being sold.
     */
    public function test_a_single_hot_short_window_does_not_outrank_a_negative_medium_term_regime(): void
    {
        $spike = $this->analyzer->analyze(
            $this->rollups([3 => 0.20, 5 => -0.02, 10 => -0.02, 20 => -0.03]),
            $this->profile,
        );

        $steady = $this->analyzer->analyze(
            $this->rollups([3 => 0.008, 5 => 0.008, 10 => 0.008, 20 => 0.008]),
            $this->profile,
        );

        // The medium-term windows are all negative and the selling is
        // concentrated, so the spike is not merely distributive but strongly
        // so -- which is the reading the 3D figure was hiding.
        $this->assertSame(BrokerRegime::STRONG_DISTRIBUTION, $spike->regime);
        $this->assertSame(BrokerRegime::ACCUMULATION, $steady->regime);
        $this->assertTrue(BrokerRegime::isDistributive($spike->regime));
        $this->assertTrue(BrokerRegime::isAccumulative($steady->regime));

        $this->assertGreaterThan(
            BrokerRegime::rank($spike->regime),
            BrokerRegime::rank($steady->regime),
            'Persistent modest accumulation must outrank a single unusually strong short window.',
        );

        $this->assertSame(1, $spike->positiveWindows);
        $this->assertSame(4, $steady->positiveWindows);
    }

    public function test_flow_inside_the_noise_floor_counts_as_neither_direction(): void
    {
        $flow = $this->analyzer->analyze(
            $this->rollups([3 => 0.0001, 5 => 0.0001, 10 => -0.0001, 20 => 0.0]),
            $this->profile,
        );

        $this->assertSame(BrokerRegime::NEUTRAL, $flow->regime);
        $this->assertSame(0, $flow->positiveWindows);
        $this->assertSame(0, $flow->negativeWindows);
    }

    public function test_a_regime_needs_a_medium_term_window_to_stand_on(): void
    {
        // Only the short window exists. One strong session is an event, not a
        // regime, and the classification says so rather than guessing.
        $flow = $this->analyzer->analyze($this->rollups([3 => 0.20]), $this->profile);

        $this->assertSame(BrokerRegime::NEUTRAL, $flow->regime);
        $this->assertSame(1, $flow->availableWindows);
        $this->assertStringContainsString('no medium-term broker window', implode(' ', $flow->reasons));
    }

    public function test_strong_accumulation_requires_concentration_as_well_as_direction(): void
    {
        // Every window positive, but the buying spread thinly across the book.
        $flow = $this->analyzer->analyze(
            $this->rollups([3 => 0.01, 5 => 0.01, 10 => 0.01, 20 => 0.01], [3 => 0.01, 5 => 0.01, 10 => 0.01, 20 => 0.01]),
            $this->profile,
        );

        $this->assertSame(BrokerRegime::ACCUMULATION, $flow->regime);
    }

    public function test_acceleration_compares_the_short_window_with_the_background(): void
    {
        $building = $this->analyzer->analyze($this->rollups([3 => 0.04, 5 => 0.02, 10 => 0.01, 20 => 0.01]), $this->profile);
        $fading = $this->analyzer->analyze($this->rollups([3 => 0.005, 5 => 0.01, 10 => 0.02, 20 => 0.03]), $this->profile);

        $this->assertEqualsWithDelta(0.03, $building->acceleration, 0.0001);
        $this->assertEqualsWithDelta(-0.025, $fading->acceleration, 0.0001);
    }

    public function test_no_windows_at_all_is_reported_rather_than_scored(): void
    {
        $flow = $this->analyzer->analyze([], $this->profile);

        $this->assertFalse($flow->hasAnyWindow());
        $this->assertSame(BrokerRegime::NEUTRAL, $flow->regime);
        $this->assertNull($flow->persistenceRatio);
    }

    /**
     * Deterioration, which is the same reading applied to an open position.
     * One soft window is not an exit.
     */
    public function test_a_single_soft_short_window_tightens_the_stop_rather_than_exiting(): void
    {
        $flow = $this->analyzer->analyze($this->rollups([3 => -0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]), $this->profile);

        $result = $this->analyzer->deterioration($flow);

        $this->assertSame(PositionAction::HOLD_TIGHTEN_STOP, $result['action']);
        $this->assertSame(1, $result['severity']);
    }

    public function test_short_and_near_term_both_negative_is_a_distribution_warning(): void
    {
        $flow = $this->analyzer->analyze($this->rollups([3 => -0.02, 5 => -0.02, 10 => 0.02, 20 => 0.02]), $this->profile);

        $result = $this->analyzer->deterioration($flow);

        $this->assertSame(PositionAction::EXIT_WARNING, $result['action']);
        $this->assertSame(2, $result['severity']);
    }

    public function test_weakening_price_raises_the_severity_of_a_distribution_warning(): void
    {
        $flow = $this->analyzer->analyze($this->rollups([3 => -0.02, 5 => -0.02, 10 => 0.02, 20 => 0.02]), $this->profile);

        $result = $this->analyzer->deterioration($flow, priceWeakening: true);

        $this->assertSame(PositionAction::EXIT_WARNING, $result['action']);
        $this->assertSame(3, $result['severity']);
    }

    public function test_intact_medium_term_flow_is_simply_a_hold(): void
    {
        $flow = $this->analyzer->analyze($this->rollups([3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]), $this->profile);

        $result = $this->analyzer->deterioration($flow);

        $this->assertSame(PositionAction::HOLD, $result['action']);
        $this->assertSame(0, $result['severity']);
    }
}
