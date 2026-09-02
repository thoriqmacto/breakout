<?php

namespace Tests\Feature\Execution;

use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\PositionRiskState;
use App\Models\Price;
use App\Models\WatchlistScore;
use App\Services\Execution\ExecutionCandidateService;
use App\Services\Execution\ExecutionStatus;
use App\Services\Strategy\BrokerRegime;
use App\Services\Strategy\PositionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lifecycle end to end: accumulation, setup, trigger, position, trailing.
 *
 * These assert the rules a reader would act on, through the same service the
 * page calls, so a change that keeps the unit tests green and breaks the
 * composition still fails here.
 */
class ExecutionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Asset> */
    private array $assets = [];

    private string $signalDate = '';

    private string $nextDate = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'execution.min_score' => 75.0,
            'execution.min_rr' => 2.0,
            'strategy_profile.minimum_probability_sample' => 30,
            'strategy_profile.max_broker_lag_days_execution' => 1,
        ]);
    }

    /**
     * Forty weekday sessions in a steady advance, then one more calendar day
     * so the plan has a real next session to be judged against.
     *
     * @return array<int, string>
     */
    private function seedSessions(string $symbol, float $start = 1000.0, float $step = 5.0, int $count = 60): array
    {
        $asset = Asset::firstOrCreate(
            ['symbol' => $symbol],
            ['name' => 'Asset '.$symbol, 'sector' => 'Energy'],
        );
        $this->assets[$symbol] = $asset;

        $cursor = Carbon::parse('2026-01-05');
        $close = $start;
        $dates = [];

        while (count($dates) < $count) {
            if ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();

                continue;
            }

            Price::create([
                'asset_id' => $asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 2,
                'high' => $close + 3,
                'low' => $close - 5,
                'close' => $close,
                'volume' => 10_000_000,
            ]);

            DB::table('trading_days')->insertOrIgnore(['date' => $cursor->toDateString()]);

            $dates[] = $cursor->toDateString();
            $close += $step;
            $cursor->addDay();
        }

        $this->signalDate = end($dates);

        // The next session exists on the calendar, and a bar for it is added
        // per-test so the entry-zone reading can be controlled.
        while ($cursor->dayOfWeekIso >= 6) {
            $cursor->addDay();
        }

        $this->nextDate = $cursor->toDateString();
        DB::table('trading_days')->insertOrIgnore(['date' => $this->nextDate]);

        return $dates;
    }

    private function seedNextBar(string $symbol, float $open, float $high, ?float $low = null, ?float $close = null): void
    {
        Price::create([
            'asset_id' => $this->assets[$symbol]->id,
            'date' => $this->nextDate,
            'open' => $open,
            'high' => $high,
            'low' => $low ?? $open - 10,
            'close' => $close ?? $open,
            'volume' => 12_000_000,
        ]);
    }

    /**
     * @param  array<int, float>  $netByWindow
     */
    private function seedBrokerFlow(string $symbol, string $endDate, array $netByWindow, float $top3Multiple = 4.0): void
    {
        foreach ($netByWindow as $days => $net) {
            DB::table('broker_accumulation_windows')->insert([
                'asset_id' => $this->assets[$symbol]->id,
                'end_date' => $endDate,
                'window_days' => $days,
                'avg_net_norm' => $net,
                'top3_net_norm' => $net * $top3Multiple,
                'top5_net_norm' => $net * $top3Multiple,
                'foreign_net_norm' => 0,
                'local_net_norm' => 0,
                'accdist_score' => $net > 0 ? 1 : ($net < 0 ? -1 : 0),
                'broker_count' => 20,
                'value' => 50_000_000_000,
                'volume' => 1_000_000,
                'covered_days' => $days,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedFeature(string $symbol, string $date, array $overrides = []): void
    {
        DB::table('features_daily')->insert(array_merge([
            'symbol' => $symbol,
            'date' => $date,
            'breakout20' => true,
            'close_pos' => 0.9,
            'vol_ratio_20' => 2.0,
            'turnover_value' => 50_000_000_000,
            'active_broker_count' => 20,
            'pbas' => 82,
            'valid_long_setup' => true,
            'bandar_dist_hard' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedScore(string $symbol, string $date, array $overrides = []): WatchlistScore
    {
        return WatchlistScore::create(array_merge([
            'scan_date' => $date,
            'symbol' => $symbol,
            'asset_id' => $this->assets[$symbol]->id,
            'version' => 'v1',
            'close' => 1000,
            'score_total' => 88.0,
            'score_bas' => 90.0,
            'score_bcs' => 85.0,
            'lf_pass' => true,
            'rrf_pass' => true,
            'risk_reward' => 3.0,
            'invalidation_level' => 900,
            'take_profit' => 1300,
            'top_brokers' => [],
            'reasons' => [],
        ], $overrides));
    }

    /**
     * A complete, current, accumulating setup. Individual tests spoil one
     * thing about it and assert what changes.
     */
    private function seedHealthySetup(string $symbol = 'AAA'): void
    {
        $this->seedSessions($symbol);
        $this->seedFeature($symbol, $this->signalDate);
        $this->seedScore($symbol, $this->signalDate);
        $this->seedBrokerFlow($symbol, $this->signalDate, [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRow(array $options = []): array
    {
        $result = app(ExecutionCandidateService::class)->candidates($options);

        $this->assertNotEmpty($result['rows'], 'Expected at least one candidate row.');

        return $result['rows'][0];
    }

    public function test_a_confirmed_breakout_with_current_data_is_triggered(): void
    {
        $this->seedHealthySetup();
        // Opens just above the trigger (one tick over the 1298 signal high,
        // rounded up the IDX ladder to 1305) and inside the half-ATR zone.
        $this->seedNextBar('AAA', 1308.0, 1320.0);

        $row = $this->firstRow();

        $this->assertSame(ExecutionStatus::TRIGGERED, $row['lifecycle_status']);
        $this->assertSame(PositionAction::BUY_ON_TRIGGER, $row['action']);
        $this->assertSame(BrokerRegime::STRONG_ACCUMULATION, $row['broker']['regime']);
        $this->assertEqualsWithDelta(1.0, $row['broker']['persistence_ratio'], 0.0001);
        $this->assertSame(4, $row['broker']['positive_windows']);
        $this->assertTrue($row['data_quality']['broker_current']);
        $this->assertTrue($row['execution_plan']['valid']);
        $this->assertSame('inside', $row['execution_plan']['entry_zone_state']);
    }

    public function test_price_beyond_the_entry_zone_is_no_chase(): void
    {
        $this->seedHealthySetup();
        // Gaps far past the trigger: the setup was real, the entry is gone.
        $this->seedNextBar('AAA', 1600.0, 1650.0);

        $row = $this->firstRow();

        $this->assertSame(ExecutionStatus::NO_CHASE, $row['lifecycle_status']);
        $this->assertSame(PositionAction::NO_CHASE, $row['action']);
        $this->assertSame('above', $row['execution_plan']['entry_zone_state']);
        $this->assertNotEmpty($row['action_reasons']);
    }

    public function test_stale_broker_data_blocks_an_executable_status(): void
    {
        $this->seedSessions('AAA');
        $this->seedFeature('AAA', $this->signalDate);
        $this->seedScore('AAA', $this->signalDate);
        // Rollups that stop well before the signal session.
        $this->seedBrokerFlow('AAA', Carbon::parse($this->signalDate)->subDays(9)->toDateString(), [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $row = $this->firstRow();

        $this->assertSame(ExecutionStatus::STALE_DATA, $row['lifecycle_status']);
        $this->assertSame(PositionAction::STALE_DATA, $row['action']);
        $this->assertFalse($row['data_quality']['broker_current']);
    }

    public function test_a_distributive_regime_is_avoided_whatever_the_price_did(): void
    {
        $this->seedSessions('AAA');
        $this->seedFeature('AAA', $this->signalDate);
        $this->seedScore('AAA', $this->signalDate);
        $this->seedBrokerFlow('AAA', $this->signalDate, [3 => 0.05, 5 => -0.02, 10 => -0.02, 20 => -0.03]);
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $row = $this->firstRow();

        $this->assertSame(ExecutionStatus::AVOID, $row['lifecycle_status']);
        $this->assertTrue(BrokerRegime::isDistributive($row['broker']['regime']));
    }

    public function test_excessive_initial_risk_rejects_the_plan(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        // A 0.5% ceiling that the ATR-based stop cannot possibly meet.
        config(['strategy_profile.max_initial_risk_pct' => 0.5]);

        $row = $this->firstRow();

        $this->assertFalse($row['execution_plan']['valid']);
        $this->assertSame('excessive_initial_risk', $row['execution_plan']['rejected_reason']);
        $this->assertSame(ExecutionStatus::AVOID, $row['lifecycle_status']);
    }

    public function test_the_plan_reports_the_entry_zone_stop_and_lifecycle_levels(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $plan = $this->firstRow()['execution_plan'];
        $profit = $this->firstRow()['profit_management'];

        $this->assertGreaterThan($plan['entry_zone_low'], $plan['entry_zone_high']);
        $this->assertLessThan($plan['trigger_price'], $plan['initial_stop']);
        $this->assertGreaterThan(0.0, $plan['initial_risk_pct']);
        $this->assertNotNull($plan['initial_stop_source']);

        // +5% activation and +3% floor, both measured from the trigger.
        $this->assertEqualsWithDelta($plan['trigger_price'] * 1.05, $profit['activation_price'], 0.5);
        $this->assertEqualsWithDelta($plan['trigger_price'] * 1.03, $profit['profit_floor_price'], 0.5);
        $this->assertSame(5.0, $profit['activation_gain_pct']);
        $this->assertSame(2.0, $profit['trailing_distance_pct']);
        $this->assertSame(3.0, $profit['minimum_locked_profit_pct']);

        // The floor is a price level; the round trip costs something to get
        // to it, and the row says how much rather than implying it is free.
        $this->assertGreaterThan(0.0, $profit['round_trip_cost_pct']);
    }

    public function test_a_thin_sample_reports_insufficient_rather_than_a_probability(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $historical = $this->firstRow()['historical_outcome'];

        $this->assertSame('INSUFFICIENT_SAMPLE', $historical['status']);
        $this->assertNull($historical['probability_hit_5_before_stop']);
        $this->assertSame(30, $historical['minimum_sample']);
    }

    public function test_a_held_position_reports_as_a_holding_not_a_fresh_candidate(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $portfolio = $this->portfolioHolding('AAA', qty: 1000, price: 1100.0, executedAt: '2026-02-02');

        $row = $this->firstRow(['portfolio_id' => $portfolio->id]);

        $this->assertContains($row['lifecycle_status'], ExecutionStatus::POSITION_STATES);
        $this->assertNotNull($row['profit_management']['position']);
        // The portfolio's real average cost, never a price reconstructed from
        // the chart.
        $this->assertEqualsWithDelta(1100.0, $row['profit_management']['position']['entry_price'], 0.0001);
    }

    public function test_a_position_past_activation_is_trailing_with_a_ratcheted_stop(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        // Entered low enough that the advance since has cleared +5%.
        $portfolio = $this->portfolioHolding('AAA', qty: 1000, price: 1000.0, executedAt: '2026-02-02');

        $row = $this->firstRow(['portfolio_id' => $portfolio->id]);
        $position = $row['profit_management']['position'];

        $this->assertTrue($position['trailing_active']);
        $this->assertNotNull($position['trailing_activated_at']);
        $this->assertEqualsWithDelta(1030.0, $position['profit_floor_price'], 0.0001);
        $this->assertGreaterThanOrEqual($position['profit_floor_price'], $position['effective_stop_price']);
        $this->assertGreaterThan(0.0, $position['locked_profit_pct']);
        $this->assertSame(ExecutionStatus::TRAILING, $row['lifecycle_status']);
    }

    public function test_a_closed_position_stops_receiving_lifecycle_updates(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $portfolio = $this->portfolioHolding('AAA', qty: 1000, price: 1000.0, executedAt: '2026-02-02');

        $this->firstRow(['portfolio_id' => $portfolio->id]);

        $state = PositionRiskState::query()->where('portfolio_id', $portfolio->id)->firstOrFail();
        $this->assertFalse($state->closed);

        // Sell the whole holding.
        Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->assets['AAA']->id,
            'side' => 'exit',
            'qty_shares' => 1000,
            'price' => 1200.0,
            'fee_value' => 0,
            'avg_price' => 1200.0,
            'executed_at' => '2026-03-01 00:00:00',
        ]);

        $row = $this->firstRow(['portfolio_id' => $portfolio->id]);

        $state->refresh();
        $this->assertTrue($state->closed);
        $this->assertNotNull($state->closed_at);

        // And the symbol is a fresh candidate again rather than a phantom hold.
        $this->assertNotContains($row['lifecycle_status'], ExecutionStatus::POSITION_STATES);
        $this->assertNull($row['profit_management']['position']);
    }

    public function test_the_row_carries_a_grouped_explanation(): void
    {
        $this->seedHealthySetup();
        $this->seedNextBar('AAA', 1300.0, 1320.0);

        $row = $this->firstRow();

        $this->assertArrayHasKey('broker', $row['reasons_v2']);
        $this->assertArrayHasKey('price', $row['reasons_v2']);
        $this->assertArrayHasKey('risk', $row['reasons_v2']);
        $this->assertArrayHasKey('history', $row['reasons_v2']);

        foreach (['broker', 'price', 'risk', 'history'] as $group) {
            $this->assertNotEmpty($row['reasons_v2'][$group], sprintf('The %s explanation is empty.', $group));
        }

        // Every scored component reports its own contribution, so the total
        // can be taken apart rather than trusted.
        $this->assertEqualsWithDelta(
            $row['execution_score_v2'],
            array_sum(array_column($row['score_components'], 'contribution')),
            0.01,
        );
    }

    private function portfolioHolding(string $symbol, float $qty, float $price, string $executedAt): Portfolio
    {
        $portfolio = Portfolio::create([
            'name' => 'Test',
            'base_ccy' => 'IDR',
            'cash_balance' => 100_000_000,
            'cash_accounting_version' => 2,
        ]);

        Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->assets[$symbol]->id,
            'side' => 'entry',
            'qty_shares' => $qty,
            'price' => $price,
            'fee_value' => 0,
            'avg_price' => $price,
            'executed_at' => $executedAt.' 00:00:00',
        ]);

        return $portfolio;
    }
}
