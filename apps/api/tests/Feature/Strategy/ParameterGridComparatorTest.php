<?php

namespace Tests\Feature\Strategy;

use App\Models\Asset;
use App\Models\Price;
use App\Models\WatchlistScore;
use App\Services\Strategy\ParameterGridComparator;
use App\Services\Strategy\StrategyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The grid, on the property that makes it worth having: every cell must see
 * the same trades, and the periods must be reported separately.
 */
class ParameterGridComparatorTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    /** @var array<int, string> */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA', 'sector' => 'Energy']);
        $this->seedSessions();
    }

    private function seedSessions(int $count = 120): void
    {
        $cursor = Carbon::parse('2026-01-05');
        $close = 1000.0;

        while (count($this->sessions) < $count) {
            if ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();

                continue;
            }

            // A gentle saw: enough movement for stops and activations to
            // actually fire under different parameters.
            $wobble = (count($this->sessions) % 7) - 3;

            Price::create([
                'asset_id' => $this->asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 2,
                'high' => $close + 25 + $wobble,
                'low' => $close - 18 + $wobble,
                'close' => $close + $wobble,
                'volume' => 10_000_000,
            ]);

            DB::table('trading_days')->insertOrIgnore(['date' => $cursor->toDateString()]);
            $this->sessions[] = $cursor->toDateString();
            $close += 12;
            $cursor->addDay();
        }
    }

    private function seedSignals(int $firstIndex, int $lastIndex): void
    {
        foreach (range($firstIndex, $lastIndex) as $index) {
            $date = $this->sessions[$index];

            foreach ([3, 5, 10, 20] as $days) {
                DB::table('broker_accumulation_windows')->updateOrInsert(
                    ['asset_id' => $this->asset->id, 'end_date' => $date, 'window_days' => $days],
                    [
                        'avg_net_norm' => 0.02, 'top3_net_norm' => 0.08, 'top5_net_norm' => 0.08,
                        'foreign_net_norm' => 0, 'local_net_norm' => 0, 'accdist_score' => 1,
                        'broker_count' => 20, 'value' => 50_000_000_000, 'volume' => 1_000_000,
                        'covered_days' => $days, 'created_at' => now(), 'updated_at' => now(),
                    ],
                );
            }

            WatchlistScore::create([
                'scan_date' => $date,
                'symbol' => 'AAA',
                'asset_id' => $this->asset->id,
                'version' => 'v1',
                'close' => 1000,
                'score_total' => 88.0,
                'score_bas' => 90.0,
                'score_bcs' => 85.0,
                'lf_pass' => true,
                'rrf_pass' => true,
                'risk_reward' => 3.0,
                'top_brokers' => [],
                'reasons' => [],
            ]);
        }
    }

    private function profile(): StrategyProfile
    {
        return StrategyProfile::fromArray([
            'version' => 'grid-test',
            'trail_activation_gain_pct' => 5.0,
            'trailing_distance_pct' => 2.0,
            'minimum_locked_profit_pct' => 3.0,
            'max_holding_sessions' => 12,
            'max_initial_risk_pct' => 30.0,
            'outcome_horizons' => [1, 5],
            'costs' => ['buy_fee_pct' => 0.15, 'sell_fee_pct' => 0.25, 'slippage_pct' => 0.1, 'round_to_tick' => false],
        ]);
    }

    public function test_every_cell_is_scored_on_the_same_signals(): void
    {
        $this->seedSignals(60, 90);

        $report = app(ParameterGridComparator::class)->compare(
            Carbon::parse($this->sessions[60]),
            Carbon::parse($this->sessions[90]),
            $this->profile(),
        );

        $this->assertGreaterThan(0, $report['trades_available']);
        $this->assertCount(5, $report['cells']);

        // Trailing parameters change how a fill is managed, never whether it
        // happened, so the trade count is identical across the grid. Two
        // cells with different signal sets would not be comparable at all.
        $tradeCounts = array_map(
            static fn (array $cell): int => (int) $cell['all']['trades'],
            $report['cells'],
        );

        $this->assertCount(1, array_unique($tradeCounts), 'Grid cells saw different numbers of trades.');
    }

    public function test_each_cell_reports_its_periods_separately(): void
    {
        $this->seedSignals(60, 90);

        $report = app(ParameterGridComparator::class)->compare(
            Carbon::parse($this->sessions[60]),
            Carbon::parse($this->sessions[90]),
            $this->profile(),
        );

        foreach ($report['cells'] as $cell) {
            foreach (['in_sample', 'validation', 'out_of_sample'] as $split) {
                $this->assertArrayHasKey($split, $cell['splits']);
                $this->assertArrayHasKey('expectancy_pct', $cell['splits'][$split]);
            }

            $this->assertSame(
                $cell['all']['trades'],
                array_sum(array_map(
                    static fn (array $metrics): int => (int) $metrics['trades'],
                    $cell['splits'],
                )),
                'The split trade counts must add up to the whole.',
            );
        }

        $this->assertNotNull($report['split_boundaries']['in_sample_end']);
        $this->assertNotNull($report['split_boundaries']['validation_end']);
    }

    public function test_the_cells_are_labelled_by_the_parameters_that_produced_them(): void
    {
        $this->seedSignals(60, 75);

        $report = app(ParameterGridComparator::class)->compare(
            Carbon::parse($this->sessions[60]),
            Carbon::parse($this->sessions[75]),
            $this->profile(),
            [
                ['trail_activation_gain_pct' => 5.0, 'trailing_distance_pct' => 2.0, 'minimum_locked_profit_pct' => 3.0],
                ['trail_activation_gain_pct' => 6.0, 'trailing_distance_pct' => 2.0, 'minimum_locked_profit_pct' => 3.5],
            ],
        );

        $this->assertCount(2, $report['cells']);
        $this->assertSame(5.0, $report['cells'][0]['parameters']['trail_activation_gain_pct']);
        $this->assertSame(6.0, $report['cells'][1]['parameters']['trail_activation_gain_pct']);

        // A varied cell must not write under the base profile's version, or
        // two rule sets would share one label.
        $this->assertNotSame($report['cells'][0]['version'], $report['cells'][1]['version']);
    }

    public function test_an_empty_range_says_so_instead_of_reporting_zeros_as_results(): void
    {
        $report = app(ParameterGridComparator::class)->compare(
            Carbon::parse($this->sessions[10]),
            Carbon::parse($this->sessions[20]),
            $this->profile(),
        );

        $this->assertSame(0, $report['trades_available']);

        foreach ($report['cells'] as $cell) {
            $this->assertSame(0, $cell['all']['trades']);
            $this->assertNull($cell['all']['expectancy_pct']);
            $this->assertNull($cell['all']['hit_rate_5pct']);
        }
    }
}
