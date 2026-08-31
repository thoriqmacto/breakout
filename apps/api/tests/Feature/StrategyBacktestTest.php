<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingDay;
use App\Models\WatchlistScore;
use App\Services\Strategy\WatchlistBacktester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StrategyBacktestTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_report_when_no_scores_exist(): void
    {
        $this->seedTradingDays(['2026-04-01', '2026-04-02']);

        $report = app(WatchlistBacktester::class)->backtest(
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
            'v1',
            [5],
            0.035
        );

        $this->assertSame(0, $report['sample_size']);
        $this->assertSame([], $report['baseline']);
        $this->assertSame([], $report['buckets']);
    }

    public function test_baseline_and_bucket_lift_are_computed_correctly(): void
    {
        // 6 trading days; horizon 2D so we can compute returns easily.
        $tradingDays = ['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06', '2026-04-07', '2026-04-08'];
        $this->seedTradingDays($tradingDays);

        // Two assets to keep the dataset small but meaningful.
        $hot = Asset::create(['symbol' => 'HOT', 'name' => 'HOT']);
        $cold = Asset::create(['symbol' => 'COLD', 'name' => 'COLD']);

        // HOT: closes that yield +5% over 2D for both scan dates.
        $this->seedBars($hot->id, [
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 1010],
            ['date' => '2026-04-03', 'close' => 1050],   // +5% vs 04-01
            ['date' => '2026-04-06', 'close' => 1080],
            ['date' => '2026-04-07', 'close' => 1100],
            ['date' => '2026-04-08', 'close' => 1140],
        ]);
        // COLD: flat-to-down → never hits target.
        $this->seedBars($cold->id, [
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 990],
            ['date' => '2026-04-03', 'close' => 980],
            ['date' => '2026-04-06', 'close' => 970],
            ['date' => '2026-04-07', 'close' => 960],
            ['date' => '2026-04-08', 'close' => 950],
        ]);

        // Two HOT scans (high score, both pass) and two COLD scans (low score, both fail).
        $this->seedScore($hot->id, 'HOT', '2026-04-01', score: 90, lf: true, rrf: true);
        $this->seedScore($hot->id, 'HOT', '2026-04-03', score: 80, lf: true, rrf: true);
        $this->seedScore($cold->id, 'COLD', '2026-04-01', score: 20, lf: false, rrf: false);
        $this->seedScore($cold->id, 'COLD', '2026-04-03', score: 10, lf: false, rrf: false);

        $report = app(WatchlistBacktester::class)->backtest(
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-08'),
            'v1',
            [2],
            0.035
        );

        $this->assertSame(4, $report['sample_size']);

        $baseline2d = $this->byHorizon($report['baseline'], 2);
        $this->assertSame(0.5, $baseline2d['hit_rate']); // 2 hits out of 4
        $this->assertSame(4, $baseline2d['n']);

        $hotBucket = collect($report['buckets'])->firstWhere('bucket', '75–100');
        $coldBucket = collect($report['buckets'])->firstWhere('bucket', '0–25');

        $this->assertSame(2, $hotBucket['n']);
        $this->assertSame(2, $coldBucket['n']);

        $hotH = $this->byHorizon($hotBucket['by_horizon'], 2);
        $coldH = $this->byHorizon($coldBucket['by_horizon'], 2);

        $this->assertSame(1.0, $hotH['hit_rate']);
        $this->assertSame(0.0, $coldH['hit_rate']);

        // Lift vs baseline: hot bucket = +0.5; cold bucket = -0.5
        $this->assertSame(0.5, $hotH['lift_vs_baseline']);
        $this->assertSame(-0.5, $coldH['lift_vs_baseline']);
    }

    public function test_filter_combos_split_observations(): void
    {
        // Four sessions, because entry is T+1: a signal on the second-to-last
        // session fills on the last one and then has no session left to exit
        // into.
        $this->seedTradingDays(['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06']);
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'AAA']);
        $this->seedBars($asset->id, [
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 1010],
            ['date' => '2026-04-03', 'close' => 1100],
            ['date' => '2026-04-06', 'close' => 1150],
        ]);

        $this->seedScore($asset->id, 'AAA', '2026-04-01', score: 60, lf: true, rrf: true);
        $this->seedScore($asset->id, 'AAA', '2026-04-02', score: 60, lf: false, rrf: true);

        $report = app(WatchlistBacktester::class)->backtest(
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-06'),
            'v1',
            [1],
            0.035
        );

        $combos = collect($report['filter_combos']);
        $this->assertSame(1, $combos->firstWhere('combo', 'LF=pass · RRF=pass')['n']);
        $this->assertSame(1, $combos->firstWhere('combo', 'LF=fail · RRF=pass')['n']);
        $this->assertSame(0, $combos->firstWhere('combo', 'LF=pass · RRF=fail')['n']);
        $this->assertSame(0, $combos->firstWhere('combo', 'LF=fail · RRF=fail')['n']);
    }

    public function test_observation_skipped_when_horizon_exceeds_available_trading_days(): void
    {
        // Only 2 trading days → horizon 5 cannot be evaluated.
        $this->seedTradingDays(['2026-04-01', '2026-04-02']);
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'AAA']);
        $this->seedBars($asset->id, [
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 1100],
        ]);
        $this->seedScore($asset->id, 'AAA', '2026-04-01', score: 80, lf: true, rrf: true);

        $report = app(WatchlistBacktester::class)->backtest(
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-02'),
            'v1',
            [5],
            0.035
        );

        $this->assertSame(0, $report['sample_size']);
    }

    public function test_command_runs_and_emits_json(): void
    {
        $this->seedTradingDays(['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06']);
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'AAA']);
        $this->seedBars($asset->id, [
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 1010],
            ['date' => '2026-04-03', 'close' => 1050],
            ['date' => '2026-04-06', 'close' => 1100],
        ]);
        $this->seedScore($asset->id, 'AAA', '2026-04-01', score: 80, lf: true, rrf: true);

        $exit = Artisan::call('strategy:backtest-watchlist', [
            '--from' => '2026-04-01',
            '--to' => '2026-04-06',
            '--horizons' => '2',
            '--target-pct' => 0.035,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = trim(Artisan::output());
        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['sample_size']);
        $this->assertSame('v1', $payload['version']);
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function seedTradingDays(array $dates): void
    {
        foreach ($dates as $i => $date) {
            TradingDay::create([
                'date' => $date,
                'close' => 1000 + $i,
            ]);
        }
    }

    /**
     * @param  array<int, array{date: string, close: int|float}>  $bars
     */
    private function seedBars(int $assetId, array $bars): void
    {
        foreach ($bars as $bar) {
            Price::create([
                'asset_id' => $assetId,
                'date' => $bar['date'],
                'open' => $bar['close'],
                'high' => $bar['close'],
                'low' => $bar['close'],
                'close' => $bar['close'],
                'volume' => 1_000_000,
            ]);
        }
    }

    private function seedScore(int $assetId, string $symbol, string $scanDate, float $score, bool $lf, bool $rrf): void
    {
        WatchlistScore::create([
            'asset_id' => $assetId,
            'scan_date' => $scanDate,
            'symbol' => $symbol,
            'version' => 'v1',
            'close' => 1000,
            'net_value' => 0,
            'vol_ratio_20' => 1.0,
            'breakout20' => false,
            'score_total' => $score,
            'score_bas' => $score,
            'score_bcs' => $score,
            'lf_pass' => $lf,
            'rrf_pass' => $rrf,
            'invalidation_level' => null,
            'take_profit' => null,
            'risk_reward' => null,
            'top_brokers' => [],
            'reasons' => [],
            'risk_notes' => null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function byHorizon(array $rows, int $horizon): array
    {
        foreach ($rows as $row) {
            if ((int) ($row['horizon'] ?? -1) === $horizon) {
                return $row;
            }
        }

        $this->fail("No row found for horizon {$horizon}.");
    }
}
