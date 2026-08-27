<?php

namespace Tests\Feature\Automation;

use App\Models\TradingCalendarDay;
use App\Models\TradingDay;
use App\Services\Automation\RunMetadata;
use App\Services\YahooTradingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The refresh keeps `trading_calendar` current, and the invariant it exists to
 * hold is that it never records a day as a holiday that the market simply has
 * not reached yet.
 *
 * TradingCalendarBuilder decides by absence: a weekday with no `trading_days`
 * row becomes `is_holiday = true`. Correct for a settled date, catastrophic for
 * a future one -- the conditions would then skip with a confident
 * `not_trading_day` instead of an honest `trading_calendar_incomplete`.
 */
class TradingCalendarRefreshTest extends TestCase
{
    use RefreshDatabase;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['automation.timezone' => 'Asia/Jakarta']);

        // trading-days:build rewrites database/seeders/data/trading_days.php
        // from whatever the database holds. Pointed at the real repository that
        // replaces 8,842 committed rows with the handful a test inserts, so
        // database_path() is redirected somewhere disposable for the duration.
        $this->databasePath = sys_get_temp_dir().'/breakout-calendar-refresh-'.bin2hex(random_bytes(4));
        mkdir($this->databasePath.'/seeders/data', 0755, true);
        $this->app->useDatabasePath($this->databasePath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->databasePath.'/seeders/data/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->databasePath.'/seeders/data');
        @rmdir($this->databasePath.'/seeders');
        @rmdir($this->databasePath);

        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * The Yahoo importer shells out to Python. Every test here stubs it, so
     * what is under test is the clamping, not the network.
     *
     * @param  array<int, string>  $dates  Trading dates the import "discovers".
     */
    private function stubYahoo(array $dates = [], ?RuntimeException $failure = null): void
    {
        $mock = Mockery::mock(YahooTradingDays::class);

        if ($failure !== null) {
            $mock->shouldReceive('import')->andThrow($failure);
        } else {
            $mock->shouldReceive('import')->andReturnUsing(function () use ($dates): int {
                foreach ($dates as $date) {
                    $this->tradingDay($date);
                }

                return count($dates);
            });
        }

        $this->app->instance(YahooTradingDays::class, $mock);
    }

    /**
     * Insert a trading day exactly as the importer and seeder do: an upsert of
     * a plain YYYY-MM-DD string.
     *
     * Writing it through the model instead would store "YYYY-MM-DD 00:00:00",
     * which TradingCalendarBuilder's string BETWEEN silently excludes at the
     * upper bound -- a trap worth not reproducing in a fixture, because it
     * would make these tests disagree with production about which days exist.
     */
    private function tradingDay(string $date): void
    {
        TradingDay::query()->upsert(
            [['date' => $date, 'close' => 7000, 'created_at' => now(), 'updated_at' => now()]],
            ['date'],
            ['close', 'updated_at'],
        );
    }

    /**
     * @return array<string, TradingCalendarDay>
     */
    private function calendar(): array
    {
        return TradingCalendarDay::query()
            ->orderBy('date')
            ->get()
            ->keyBy(static fn (TradingCalendarDay $row): string => Carbon::parse($row->date)->toDateString())
            ->all();
    }

    public function test_today_is_never_marked_a_holiday_before_its_bar_exists(): void
    {
        // 09:00 UTC on Friday is 16:00 WIB — the closing bell. Yahoo has
        // Monday to Thursday and has not published Friday yet.
        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'] as $date) {
            $this->tradingDay($date);
        }

        $this->stubYahoo();

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 10]);

        $calendar = $this->calendar();

        // The whole point: Friday gets no row at all, rather than a row
        // claiming the market was shut.
        $this->assertArrayNotHasKey('2026-08-28', $calendar);
        $this->assertTrue($calendar['2026-08-27']->is_trading_day);

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('2026-08-27', $metadata['last_observed_trading_date']);
        $this->assertSame('2026-08-27', $metadata['calendar_to']);
        $this->assertFalse($metadata['today_covered']);
    }

    public function test_today_is_written_once_its_bar_arrives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'] as $date) {
            $this->tradingDay($date);
        }

        // Yahoo has now published Friday.
        $this->stubYahoo(['2026-08-28']);

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 10]);

        $calendar = $this->calendar();

        $this->assertTrue($calendar['2026-08-28']->is_trading_day);
        $this->assertTrue(app(RunMetadata::class)->get('today_covered'));
        $this->assertSame(0, app(RunMetadata::class)->get('days_behind'));
    }

    public function test_a_settled_weekday_without_a_bar_is_correctly_a_holiday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 11:00:00', 'UTC'));

        // Friday the 28th never traded, and Monday the 31st did — which is
        // what proves the 28th was a holiday rather than merely unreached.
        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-31'] as $date) {
            $this->tradingDay($date);
        }

        $this->stubYahoo();

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 14]);

        $calendar = $this->calendar();

        $this->assertFalse($calendar['2026-08-28']->is_trading_day);
        $this->assertTrue($calendar['2026-08-28']->is_holiday);
        $this->assertTrue($calendar['2026-08-31']->is_trading_day);
    }

    public function test_weekends_are_still_recorded_as_weekends_not_holidays(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 11:00:00', 'UTC'));

        foreach (['2026-08-27', '2026-08-28', '2026-08-31'] as $date) {
            $this->tradingDay($date);
        }

        $this->stubYahoo();

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 14]);

        $calendar = $this->calendar();

        $this->assertTrue($calendar['2026-08-29']->is_weekend);
        $this->assertFalse($calendar['2026-08-29']->is_holiday);
        $this->assertFalse($calendar['2026-08-29']->is_trading_day);
    }

    public function test_a_failed_yahoo_import_still_rebuilds_from_what_is_stored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        foreach (['2026-08-26', '2026-08-27'] as $date) {
            $this->tradingDay($date);
        }

        $this->stubYahoo(failure: new RuntimeException('yfinance is unreachable'));

        $exit = Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 10]);

        // Losing the already-stored calendar because the network was down
        // would be a worse outcome than a calendar a day or two behind.
        $this->assertSame(0, $exit);
        $this->assertTrue($this->calendar()['2026-08-27']->is_trading_day);

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('failed', $metadata['trading_days_import']['status']);
        $this->assertTrue($metadata['partial']);
        $this->assertStringContainsString('unreachable', (string) $metadata['error_summary']);
    }

    public function test_an_empty_trading_days_table_refuses_to_invent_a_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        $this->stubYahoo();

        $exit = Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 10]);

        $this->assertSame(1, $exit);
        // Building anyway would have marked every weekday in range a holiday.
        $this->assertSame(0, TradingCalendarDay::query()->count());
        $this->assertSame('no_trading_days', app(RunMetadata::class)->get('skip_reason'));
    }

    public function test_a_stale_calendar_is_reported_as_partial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        // The last bar is a fortnight old: the import has been failing and
        // every market-day condition is skipping as a result.
        $this->tradingDay('2026-08-12');
        $this->stubYahoo();

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 30]);

        $metadata = app(RunMetadata::class)->all();

        $this->assertSame(16, $metadata['days_behind']);
        $this->assertTrue($metadata['partial']);
        $this->assertStringContainsString('days behind', (string) $metadata['error_summary']);
    }

    public function test_skip_import_rebuilds_without_touching_yahoo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        $this->tradingDay('2026-08-27');

        $mock = Mockery::mock(YahooTradingDays::class);
        $mock->shouldReceive('import')->never();
        $this->app->instance(YahooTradingDays::class, $mock);

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 10, '--skip-import' => true]);

        $this->assertTrue($this->calendar()['2026-08-27']->is_trading_day);
        $this->assertSame('skipped', app(RunMetadata::class)->get('trading_days_import')['status']);
    }

    public function test_the_market_date_is_resolved_in_jakarta(): void
    {
        // 23:30 UTC on the 27th is already the 28th in Jakarta.
        Carbon::setTestNow(Carbon::parse('2026-08-27 23:30:00', 'UTC'));

        $this->tradingDay('2026-08-27');
        $this->stubYahoo();

        Artisan::call('automation:trading-calendar-refresh', ['--lookback' => 5]);

        $this->assertSame('2026-08-28', app(RunMetadata::class)->get('market_date'));
    }
}
