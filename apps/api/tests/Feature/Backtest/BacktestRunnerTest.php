<?php

namespace Tests\Feature\Backtest;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\Price;
use App\Services\Backtest\BacktestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A backtest that only existed as terminal output could not be compared.
 *
 * The run loop lived inside `asset:backtest`, so a result was printed and
 * gone: nothing to link to, nothing to hold last week's parameters against,
 * and nothing the dashboard could show. The runner is now the single
 * implementation and the command one of its callers.
 */
class BacktestRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function assetWithHistory(string $symbol, int $days = 220): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);

        $date = Carbon::parse('2024-01-01');

        for ($i = 0; $i < $days; $i++) {
            // A rising series with a pullback every 20 sessions, so a breakout
            // strategy has something to find rather than a straight line it
            // would either always or never be long.
            $base = 1000 + ($i * 5) - (($i % 20) * 12);

            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->copy()->addDays($i)->toDateString(),
                'open' => $base,
                'high' => $base + 15,
                'low' => $base - 15,
                'close' => $base + 5,
                'volume' => 100_000 + $i,
            ]);
        }

        return $asset;
    }

    public function test_a_run_is_stored_with_the_symbol_strategy_and_stats(): void
    {
        $asset = $this->assetWithHistory('AAA');

        $run = app(BacktestRunner::class)->run('AAA', 'DonchBO', [
            'capital' => 1_000_000,
            'source' => 'cli',
        ]);

        $this->assertDatabaseHas('backtests', [
            'run_id' => $run->run_id,
            'asset_id' => $asset->id,
            'symbol' => 'AAA',
            'strategy' => 'DonchBO',
            'source' => 'cli',
        ]);

        // The fields the list and comparison views filter on are columns, not
        // buried in params_json where every query would become a scan.
        $this->assertSame('AAA', $run->symbol);
        $this->assertSame('DonchBO', $run->strategy);

        $stats = $run->stats_json;
        $this->assertArrayHasKey('cagr', $stats);
        $this->assertArrayHasKey('maxdd', $stats);
        $this->assertArrayHasKey('sharpe', $stats);
        $this->assertArrayHasKey('winrate', $stats);
        $this->assertArrayHasKey('profit_factor', $stats);
        $this->assertArrayHasKey('trades', $stats);
        $this->assertArrayHasKey('final_equity', $stats);
        $this->assertArrayHasKey('return_pct', $stats);

        // Loosely compared on purpose: a whole float survives the JSON round
        // trip as an int, and pinning the PHP type here would be testing the
        // encoder rather than the run.
        $this->assertEquals(1_000_000, $run->params_json['capital']);
        $this->assertSame(220, $run->params_json['bars']);
    }

    public function test_trades_are_stored_against_the_run(): void
    {
        $this->assetWithHistory('BBB');

        $run = app(BacktestRunner::class)->run('BBB', 'DonchBO');

        $this->assertSame(
            (int) $run->stats_json['trades'],
            $run->trades()->count(),
            'The trade count in the stats must match the trades actually stored.',
        );
    }

    /**
     * A strategy that found nothing is a result, not a run to discard.
     *
     * A table that keeps only the interesting runs cannot be used to compare
     * strategies honestly -- the comparison would silently exclude every
     * strategy that did not fire.
     */
    public function test_a_run_with_no_trades_is_still_stored(): void
    {
        $asset = Asset::create(['symbol' => 'FLAT', 'name' => 'Flat Tbk']);

        $date = Carbon::parse('2024-01-01');

        // A dead-flat series: no breakout can occur.
        for ($i = 0; $i < 120; $i++) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->copy()->addDays($i)->toDateString(),
                'open' => 1000,
                'high' => 1000,
                'low' => 1000,
                'close' => 1000,
                'volume' => 1000,
            ]);
        }

        $run = app(BacktestRunner::class)->run('FLAT', 'DonchBO');

        $this->assertDatabaseHas('backtests', ['run_id' => $run->run_id]);
        $this->assertSame(0, $run->trades()->count());
    }

    public function test_a_date_range_limits_the_bars_used(): void
    {
        $this->assetWithHistory('CCC');

        $run = app(BacktestRunner::class)->run('CCC', 'DonchBO', [
            'from' => '2024-02-01',
            'to' => '2024-04-30',
        ]);

        $this->assertSame('2024-02-01', $run->params_json['from']);
        $this->assertSame('2024-04-30', $run->params_json['to']);
        $this->assertLessThan(220, $run->params_json['bars']);
    }

    public function test_an_unknown_strategy_names_the_ones_that_exist(): void
    {
        $this->assetWithHistory('DDD');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DonchBO');

        app(BacktestRunner::class)->run('DDD', 'NoSuchStrategy');
    }

    /**
     * Zeroes that look like a strategy finding nothing are worse than an error.
     */
    public function test_too_little_history_is_refused_rather_than_reported_as_flat(): void
    {
        $asset = Asset::create(['symbol' => 'THIN', 'name' => 'Thin Tbk']);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-01',
            'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough price history');

        app(BacktestRunner::class)->run('THIN', 'DonchBO');
    }

    public function test_compare_runs_every_daily_strategy_and_stores_each(): void
    {
        $this->assetWithHistory('EEE');

        $runs = app(BacktestRunner::class)->compare('EEE', ['source' => 'api']);

        $this->assertNotEmpty($runs);

        // HLSLBreakout emits no daily signal, so it is not among them: a flat
        // equity curve would read as "tried and failed" rather than "not
        // applicable to a daily series".
        $this->assertArrayNotHasKey('HLSLBreakout', $runs);

        foreach ($runs as $key => $run) {
            $this->assertSame($key, $run->strategy);
            $this->assertDatabaseHas('backtests', ['run_id' => $run->run_id, 'symbol' => 'EEE']);
        }

        $this->assertSame(count($runs), Backtest::where('symbol', 'EEE')->count());
    }

    /**
     * The command keeps working and now leaves a row behind.
     */
    public function test_the_command_persists_through_the_same_runner(): void
    {
        $this->assetWithHistory('FFF');

        $this->artisan('asset:backtest --sym=FFF --strategy=DonchBO')
            ->assertExitCode(0);

        $run = Backtest::where('symbol', 'FFF')->first();

        $this->assertNotNull($run, 'A CLI backtest must be stored like any other.');
        $this->assertSame('cli', $run->source);
        $this->assertSame('DonchBO', $run->strategy);
    }
}
