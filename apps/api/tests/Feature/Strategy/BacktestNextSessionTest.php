<?php

namespace Tests\Feature\Strategy;

use App\Models\Asset;
use App\Models\Price;
use App\Models\WatchlistScore;
use App\Services\Strategy\WatchlistBacktester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The correction at the heart of the backtest: a score computed from session
 * T's completed bar did not exist while T was trading, so it cannot be filled
 * at T's close.
 */
class BacktestNextSessionTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['execution.min_rr' => 2.0, 'execution.max_entry_gap_pct' => null]);

        $this->asset = Asset::create(['symbol' => 'AAA', 'name' => 'AAA']);
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function tradingDays(array $dates): void
    {
        foreach ($dates as $date) {
            DB::table('trading_days')->insertOrIgnore(['date' => $date]);
        }
    }

    /**
     * @param  array<int, array{date: string, open?: float, high?: float, low?: float, close: float}>  $bars
     */
    private function bars(array $bars): void
    {
        foreach ($bars as $bar) {
            Price::create([
                'asset_id' => $this->asset->id,
                'date' => $bar['date'],
                'open' => $bar['open'] ?? $bar['close'],
                'high' => $bar['high'] ?? $bar['close'],
                'low' => $bar['low'] ?? $bar['close'],
                'close' => $bar['close'],
                'volume' => 1_000_000,
            ]);
        }
    }

    private function score(string $scanDate, ?float $stop = null, ?float $target = null): void
    {
        WatchlistScore::create([
            'asset_id' => $this->asset->id,
            'scan_date' => $scanDate,
            'symbol' => 'AAA',
            'version' => 'v1',
            'close' => 1000,
            'score_total' => 80,
            'score_bas' => 80,
            'score_bcs' => 80,
            'lf_pass' => true,
            'rrf_pass' => true,
            'invalidation_level' => $stop,
            'take_profit' => $target,
            'top_brokers' => [],
            'reasons' => [],
        ]);
    }

    private function backtest(array $overrides = []): array
    {
        return app(WatchlistBacktester::class)->backtest(
            Carbon::parse($overrides['from'] ?? '2026-04-01'),
            Carbon::parse($overrides['to'] ?? '2026-04-30'),
            'v1',
            $overrides['horizons'] ?? [1],
            $overrides['target'] ?? 0.035,
            null,
            $overrides['entry'] ?? WatchlistBacktester::ENTRY_NEXT_OPEN,
            $overrides['min_rr'] ?? null,
            $overrides['max_gap'] ?? null,
        );
    }

    public function test_a_signal_at_t_is_never_filled_at_the_t_close(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);

        // T closes at 1000 and T+1 opens far away, so the two entry prices are
        // impossible to confuse.
        $this->bars([
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'open' => 1200, 'high' => 1250, 'low' => 1180, 'close' => 1220],
            ['date' => '2026-04-03', 'close' => 1300],
        ]);
        $this->score('2026-04-01');

        $report = $this->backtest();

        $this->assertSame(1, $report['sample_size']);

        // From T's close of 1000 the 1-session return would be +22%. From
        // T+1's open of 1200 it is (1300-1200)/1200 = +8.33%.
        $this->assertEqualsWithDelta(
            0.083333,
            $this->byHorizon($report['baseline'], 1)['avg_return'],
            0.0001,
        );
    }

    public function test_a_friday_signal_enters_on_monday(): void
    {
        // 2026-04-03 is a Friday; the next session is Monday the 6th.
        $this->tradingDays(['2026-04-02', '2026-04-03', '2026-04-06', '2026-04-07']);
        $this->bars([
            ['date' => '2026-04-02', 'close' => 990],
            ['date' => '2026-04-03', 'close' => 1000],
            ['date' => '2026-04-06', 'open' => 1010, 'close' => 1020],
            ['date' => '2026-04-07', 'close' => 1100],
        ]);
        $this->score('2026-04-03');

        $report = $this->backtest();

        $this->assertSame(1, $report['sample_size']);

        // Entered at Monday's open of 1010, exited at Tuesday's close of 1100.
        // A calendar-day model would have looked for a Saturday session.
        $this->assertEqualsWithDelta(
            (1100 - 1010) / 1010,
            $this->byHorizon($report['baseline'], 1)['avg_return'],
            0.0001,
        );
    }

    public function test_a_signal_with_no_following_session_is_counted_not_dropped(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02']);
        $this->bars([
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'close' => 1100],
        ]);
        $this->score('2026-04-02');

        $report = $this->backtest();

        $this->assertSame(0, $report['sample_size']);
        $this->assertSame(1, $report['flow']['no_next_session']);
    }

    public function test_a_breakout_trigger_that_is_never_touched_is_not_a_trade(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);
        $this->bars([
            ['date' => '2026-04-01', 'open' => 990, 'high' => 1010, 'low' => 980, 'close' => 1000],
            // Next session never reaches the signal high, let alone a tick above it.
            ['date' => '2026-04-02', 'open' => 985, 'high' => 1000, 'low' => 970, 'close' => 990],
            ['date' => '2026-04-03', 'close' => 1200],
        ]);
        $this->score('2026-04-01');

        $report = $this->backtest(['entry' => WatchlistBacktester::ENTRY_BREAKOUT_TRIGGER]);

        $this->assertSame(0, $report['sample_size']);
        $this->assertSame(1, $report['flow']['not_triggered']);
    }

    public function test_a_gap_above_the_trigger_fills_at_the_open_not_the_trigger(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);
        $this->bars([
            ['date' => '2026-04-01', 'open' => 990, 'high' => 1010, 'low' => 980, 'close' => 1000],
            // Opens far above the trigger. Filling at the trigger would be a
            // price nobody could have paid: it is below every print of the day.
            ['date' => '2026-04-02', 'open' => 1100, 'high' => 1150, 'low' => 1090, 'close' => 1120],
            ['date' => '2026-04-03', 'close' => 1200],
        ]);
        $this->score('2026-04-01');

        $report = $this->backtest(['entry' => WatchlistBacktester::ENTRY_BREAKOUT_TRIGGER]);

        $this->assertSame(1, $report['sample_size']);

        // Filled at 1100, not at the ~1015 trigger.
        $this->assertEqualsWithDelta(
            (1200 - 1100) / 1100,
            $this->byHorizon($report['baseline'], 1)['avg_return'],
            0.0001,
        );
    }

    public function test_a_setup_that_fails_risk_reward_at_the_fill_is_rejected(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);
        $this->bars([
            ['date' => '2026-04-01', 'open' => 990, 'high' => 1010, 'low' => 980, 'close' => 1000],
            // Gaps up to 1180. The stop stays at 900 and the target at 1100,
            // so the reward has almost vanished while the risk has grown.
            ['date' => '2026-04-02', 'open' => 1080, 'high' => 1150, 'low' => 1070, 'close' => 1120],
            ['date' => '2026-04-03', 'close' => 1200],
        ]);
        $this->score('2026-04-01', stop: 900.0, target: 1100.0);

        // At the signal close of 1000 this is (1100-1000)/(1000-900) = 1.0 R;
        // at the 1080 fill it is (1100-1080)/(1080-900) = 0.11 R.
        $report = $this->backtest(['min_rr' => 0.5]);

        $this->assertSame(0, $report['sample_size']);
        $this->assertSame(1, $report['flow']['rejected_risk_reward']);
    }

    public function test_the_gap_guard_rejects_an_entry_far_beyond_the_trigger(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);
        $this->bars([
            ['date' => '2026-04-01', 'open' => 990, 'high' => 1010, 'low' => 980, 'close' => 1000],
            ['date' => '2026-04-02', 'open' => 1200, 'high' => 1250, 'low' => 1190, 'close' => 1220],
            ['date' => '2026-04-03', 'close' => 2000],
        ]);
        $this->score('2026-04-01', stop: 900.0, target: 5000.0);

        // Without the guard the trade is taken and looks excellent.
        $ungurded = $this->backtest(['entry' => WatchlistBacktester::ENTRY_BREAKOUT_TRIGGER]);
        $this->assertSame(1, $ungurded['sample_size']);

        // With a 5% guard the ~18% gap past the trigger disqualifies it.
        $guarded = $this->backtest(['entry' => WatchlistBacktester::ENTRY_BREAKOUT_TRIGGER, 'max_gap' => 0.05]);
        $this->assertSame(0, $guarded['sample_size']);
        $this->assertSame(1, $guarded['flow']['rejected_risk_reward']);
    }

    public function test_mfe_and_mae_are_measured_from_the_fill(): void
    {
        $this->tradingDays(['2026-04-01', '2026-04-02', '2026-04-03']);
        $this->bars([
            ['date' => '2026-04-01', 'close' => 1000],
            ['date' => '2026-04-02', 'open' => 1000, 'high' => 1050, 'low' => 950, 'close' => 1000],
            ['date' => '2026-04-03', 'open' => 1000, 'high' => 1200, 'low' => 900, 'close' => 1000],
        ]);
        $this->score('2026-04-01');

        $report = $this->backtest();
        $row = $this->byHorizon($report['baseline'], 1);

        // Filled at 1000; best print over the window 1200, worst 900.
        $this->assertEqualsWithDelta(0.20, $row['avg_mfe'], 0.0001);
        $this->assertEqualsWithDelta(-0.10, $row['avg_mae'], 0.0001);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function byHorizon(array $rows, int $horizon): array
    {
        foreach ($rows as $row) {
            if ((int) $row['horizon'] === $horizon) {
                return $row;
            }
        }

        $this->fail('No row for horizon '.$horizon);
    }
}
