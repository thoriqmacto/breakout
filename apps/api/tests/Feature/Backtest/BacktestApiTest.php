<?php

namespace Tests\Feature\Backtest;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard runs the same backtest the terminal does.
 *
 * Synchronous rather than queued: a single symbol over a couple of years takes
 * tens of milliseconds, the point of the feature is changing a parameter and
 * looking again, and queueing would put it behind a worker for no gain.
 */
class BacktestApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Signed in per test rather than in setUp, so the one test that asserts
     * the endpoint is protected is not quietly authenticated by the fixture
     * meant to serve the others.
     */
    private function signIn(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function assetWithHistory(string $symbol, int $days = 200): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);
        $date = Carbon::parse('2024-01-01');

        for ($i = 0; $i < $days; $i++) {
            $base = 1000 + ($i * 5) - (($i % 20) * 12);

            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->copy()->addDays($i)->toDateString(),
                'open' => $base,
                'high' => $base + 15,
                'low' => $base - 15,
                'close' => $base + 5,
                'volume' => 100_000,
            ]);
        }

        return $asset;
    }

    public function test_running_a_backtest_requires_authentication(): void
    {
        $this->postJson('/api/v1/backtests', ['symbol' => 'AAA', 'strategy' => 'DonchBO'])
            ->assertUnauthorized();
    }

    public function test_a_run_returns_its_stats_and_trades_and_is_stored(): void
    {
        $this->signIn();

        $this->assetWithHistory('AAA');

        $response = $this->postJson('/api/v1/backtests', [
            'symbol' => 'AAA',
            'strategy' => 'DonchBO',
            'capital' => 5_000_000,
        ])->assertCreated();

        $runId = $response->json('data.run.run_id');

        $this->assertNotNull($runId);
        $response->assertJsonPath('data.run.symbol', 'AAA')
            ->assertJsonPath('data.run.strategy', 'DonchBO')
            ->assertJsonPath('data.run.source', 'api');

        $this->assertIsArray($response->json('data.run.stats'));
        $this->assertIsArray($response->json('data.run.trades'));

        $this->assertDatabaseHas('backtests', ['run_id' => $runId, 'symbol' => 'AAA']);
    }

    /**
     * The three ways a request can be wrong are all the caller's to fix.
     */
    public function test_an_unknown_symbol_is_a_422_naming_the_problem(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/backtests', ['symbol' => 'NOPE', 'strategy' => 'DonchBO'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No asset found for symbol "NOPE".');
    }

    public function test_an_unknown_strategy_is_a_422_listing_the_ones_that_exist(): void
    {
        $this->signIn();

        $this->assetWithHistory('BBB');

        $response = $this->postJson('/api/v1/backtests', [
            'symbol' => 'BBB',
            'strategy' => 'NotAStrategy',
        ])->assertStatus(422);

        $this->assertStringContainsString('DonchBO', (string) $response->json('message'));
    }

    public function test_a_range_with_too_little_history_is_refused(): void
    {
        $this->signIn();

        $this->assetWithHistory('CCC');

        $this->postJson('/api/v1/backtests', [
            'symbol' => 'CCC',
            'strategy' => 'DonchBO',
            'from' => '2024-01-01',
            'to' => '2024-01-01',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_history_can_be_filtered_by_symbol_and_strategy(): void
    {
        $this->signIn();

        $this->assetWithHistory('DDD');
        $this->assetWithHistory('EEE');

        $this->postJson('/api/v1/backtests', ['symbol' => 'DDD', 'strategy' => 'DonchBO'])->assertCreated();
        $this->postJson('/api/v1/backtests', ['symbol' => 'EEE', 'strategy' => 'DonchBO'])->assertCreated();
        $this->postJson('/api/v1/backtests', ['symbol' => 'DDD', 'strategy' => 'AtrBO'])->assertCreated();

        $this->getJson('/api/v1/backtests?symbol=DDD')
            ->assertOk()
            ->assertJsonCount(2, 'data.runs');

        $this->getJson('/api/v1/backtests?symbol=DDD&strategy=AtrBO')
            ->assertOk()
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.strategy', 'AtrBO');
    }

    /**
     * Rows from the forecasting command describe something else entirely.
     */
    public function test_runs_without_a_strategy_are_not_listed_as_backtests(): void
    {
        $this->signIn();

        Backtest::create([
            'run_id' => 'legacy-forecast-row',
            'created_at' => Carbon::now(),
            'params_json' => ['horizon' => 10],
            'stats_json' => ['mape' => 4.2],
        ]);

        $this->getJson('/api/v1/backtests')
            ->assertOk()
            ->assertJsonCount(0, 'data.runs');
    }

    public function test_a_single_run_can_be_read_back_with_its_trades(): void
    {
        $this->signIn();

        $this->assetWithHistory('FFF');

        $runId = $this->postJson('/api/v1/backtests', ['symbol' => 'FFF', 'strategy' => 'DonchBO'])
            ->assertCreated()
            ->json('data.run.run_id');

        $this->getJson("/api/v1/backtests/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.run.run_id', $runId)
            ->assertJsonStructure(['data' => ['run' => ['trades']]]);
    }

    public function test_a_missing_run_is_a_404(): void
    {
        $this->signIn();

        $this->getJson('/api/v1/backtests/does-not-exist')->assertStatus(404);
    }

    /**
     * Comparison reads stored runs; a strategy never run is absent, not zero.
     */
    public function test_the_comparison_lists_every_strategy_and_marks_the_unrun_ones(): void
    {
        $this->signIn();

        $this->assetWithHistory('GGG');

        $this->postJson('/api/v1/backtests', ['symbol' => 'GGG', 'strategy' => 'DonchBO'])->assertCreated();

        $response = $this->getJson('/api/v1/backtests/comparison?symbol=GGG')->assertOk();

        $rows = collect($response->json('data.strategies'));

        $this->assertGreaterThan(1, $rows->count());

        $donch = $rows->firstWhere('strategy', 'DonchBO');
        $this->assertNotNull($donch['run'], 'A strategy that was run must carry its run.');

        $others = $rows->where('strategy', '!=', 'DonchBO');
        $this->assertTrue(
            $others->every(fn (array $row): bool => $row['run'] === null),
            'A strategy never run must be null rather than a row of zeroes.',
        );
    }

    /**
     * "comparison" must not be swallowed as a run id.
     */
    public function test_the_comparison_route_is_not_captured_by_the_show_route(): void
    {
        $this->signIn();

        $this->assetWithHistory('HHH');

        $this->getJson('/api/v1/backtests/comparison?symbol=HHH')
            ->assertOk()
            ->assertJsonPath('data.symbol', 'HHH');
    }

    public function test_compare_runs_every_strategy_in_one_request(): void
    {
        $this->signIn();

        $this->assetWithHistory('III');

        $runs = $this->postJson('/api/v1/backtests', [
            'symbol' => 'III',
            'compare' => true,
        ])->assertCreated()->json('data.runs');

        $this->assertGreaterThan(1, count($runs));
        $this->assertSame(count($runs), Backtest::where('symbol', 'III')->count());
    }
}
