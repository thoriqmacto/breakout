<?php

namespace Tests\Unit\Services\Strategy;

use App\Services\Strategy\StrategyScoringService;
use PHPUnit\Framework\TestCase;

class StrategyScoringServiceTest extends TestCase
{
    public function test_broker_accumulation_returns_zero_when_no_windows(): void
    {
        $service = new StrategyScoringService;
        $result = $service->brokerAccumulation([]);

        $this->assertSame(0.0, $result['score']);
        $this->assertContains('no broker accumulation windows available', $result['reasons']);
    }

    public function test_broker_accumulation_score_within_bounds(): void
    {
        $service = new StrategyScoringService;
        $result = $service->brokerAccumulation([
            ['window_days' => 3, 'avg_net_norm' => 0.05, 'top3_net_norm' => 0.04, 'accdist_score' => 1],
            ['window_days' => 5, 'avg_net_norm' => 0.03, 'top3_net_norm' => 0.02, 'accdist_score' => 1],
            ['window_days' => 10, 'avg_net_norm' => 0.02, 'top3_net_norm' => 0.01, 'accdist_score' => 0],
            ['window_days' => 20, 'avg_net_norm' => 0.015, 'top3_net_norm' => 0.005, 'accdist_score' => 0],
        ]);

        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(100.0, $result['score']);
        $this->assertGreaterThan(50.0, $result['score'], 'positive net flow should score above neutral');
    }

    public function test_broker_accumulation_negative_flow_scores_below_neutral(): void
    {
        $service = new StrategyScoringService;
        $result = $service->brokerAccumulation([
            ['window_days' => 5, 'avg_net_norm' => -0.05, 'top3_net_norm' => -0.04, 'accdist_score' => -1],
        ]);

        $this->assertLessThan(50.0, $result['score']);
    }

    public function test_breakout_confirmation_full_score_on_strong_signals(): void
    {
        $service = new StrategyScoringService;
        $result = $service->breakoutConfirmation([
            'breakout20' => true,
            'close_pos' => 0.9,
            'vol_ratio_20' => 2.0,
        ]);

        $this->assertSame(100.0, $result['score']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_breakout_confirmation_partial_credit_for_above_average_volume(): void
    {
        $service = new StrategyScoringService;
        $result = $service->breakoutConfirmation([
            'breakout20' => false,
            'close_pos' => 0.5,
            'vol_ratio_20' => 1.2,
        ]);

        // Half-credit for vol_ratio in [1.0..1.5)
        $this->assertSame(12.5, $result['score']);
    }

    public function test_breakout_confirmation_zero_when_no_signals(): void
    {
        $service = new StrategyScoringService;
        $result = $service->breakoutConfirmation([
            'breakout20' => false,
            'close_pos' => 0.4,
            'vol_ratio_20' => 0.8,
        ]);

        $this->assertSame(0.0, $result['score']);
    }

    public function test_liquidity_filter_passes_above_thresholds(): void
    {
        $service = new StrategyScoringService;
        $result = $service->liquidityFilter(
            turnoverValue: 10_000_000_000,
            activeBrokerCount: 12,
            minTurnover: 5_000_000_000,
            minBrokers: 5
        );

        $this->assertTrue($result['pass']);
        $this->assertStringContainsString('turnover', $result['reason']);
    }

    public function test_liquidity_filter_fails_low_turnover(): void
    {
        $service = new StrategyScoringService;
        $result = $service->liquidityFilter(
            turnoverValue: 1_000_000_000,
            activeBrokerCount: 20,
            minTurnover: 5_000_000_000
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('below', $result['reason']);
    }

    public function test_liquidity_filter_fails_few_brokers(): void
    {
        $service = new StrategyScoringService;
        $result = $service->liquidityFilter(
            turnoverValue: 10_000_000_000,
            activeBrokerCount: 2,
            minTurnover: 5_000_000_000,
            minBrokers: 5
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('active brokers', $result['reason']);
    }

    public function test_risk_reward_filter(): void
    {
        $service = new StrategyScoringService;

        $this->assertTrue($service->riskRewardFilter(2.5)['pass']);
        $this->assertFalse($service->riskRewardFilter(1.5)['pass']);
        $this->assertFalse($service->riskRewardFilter(null)['pass']);
        $this->assertTrue($service->riskRewardFilter(2.0)['pass']);
    }

    public function test_total_combines_subscores_with_documented_weights(): void
    {
        $service = new StrategyScoringService;
        $result = $service->total(80.0, 60.0, true, true);

        // 0.45*80 + 0.35*60 + 0.20*100 = 36 + 21 + 20 = 77
        $this->assertSame(77.0, $result['score_total']);
        $this->assertSame(36.0, $result['contribution']['bas']);
        $this->assertSame(21.0, $result['contribution']['bcs']);
        $this->assertSame(20.0, $result['contribution']['filters']);
    }

    public function test_total_caps_at_100(): void
    {
        $service = new StrategyScoringService;
        $result = $service->total(100.0, 100.0, true, true);

        // 0.45*100 + 0.35*100 + 0.20*100 = 100
        $this->assertSame(100.0, $result['score_total']);
    }

    public function test_filter_failures_drop_total_score(): void
    {
        $service = new StrategyScoringService;
        $passed = $service->total(80.0, 60.0, true, true)['score_total'];
        $failed = $service->total(80.0, 60.0, false, false)['score_total'];

        $this->assertGreaterThan($failed, $passed);
        $this->assertSame(20.0, $passed - $failed);
    }
}
