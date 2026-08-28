<?php

namespace Tests\Unit\Services\Automation;

use App\Models\TradingCalendarDay;
use App\Services\Automation\TradingWeekResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The week resolver is what decides when the weekly broker summary runs and
 * what range it covers, so every wrong answer here becomes a wrong window in
 * the database. The cases below are the ones a Monday-to-Friday assumption
 * gets wrong.
 */
class TradingWeekResolverTest extends TestCase
{
    use RefreshDatabase;

    private TradingWeekResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['automation.timezone' => 'Asia/Jakarta']);

        $this->resolver = app(TradingWeekResolver::class);
    }

    /**
     * @param  array<int, string>  $tradingDates
     */
    private function calendar(string $from, string $to, array $tradingDates): void
    {
        for ($cursor = Carbon::parse($from); $cursor->lessThanOrEqualTo(Carbon::parse($to)); $cursor->addDay()) {
            $date = $cursor->toDateString();

            TradingCalendarDay::create([
                'date' => $date,
                'is_trading_day' => in_array($date, $tradingDates, true),
                'is_weekend' => $cursor->dayOfWeekIso >= 6,
                'is_holiday' => $cursor->dayOfWeekIso < 6 && ! in_array($date, $tradingDates, true),
            ]);
        }
    }

    /**
     * The week of Monday 2026-08-24 .. Sunday 2026-08-30.
     */
    private function moment(string $date, string $time = '16:00'): Carbon
    {
        return Carbon::parse($date.' '.$time, 'Asia/Jakarta');
    }

    public function test_normal_week_runs_monday_to_friday(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $week = $this->resolver->resolveWeek($this->moment('2026-08-28'));

        $this->assertSame(TradingWeekResolver::STATUS_OK, $week['status']);
        $this->assertSame('2026-08-24', $week['from']);
        $this->assertSame('2026-08-28', $week['to']);
    }

    public function test_monday_holiday_moves_the_range_start_to_tuesday(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $week = $this->resolver->resolveWeek($this->moment('2026-08-28'));

        $this->assertSame('2026-08-25', $week['from']);
        $this->assertSame('2026-08-28', $week['to']);
    }

    public function test_friday_holiday_moves_the_range_end_to_thursday(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        $week = $this->resolver->resolveWeek($this->moment('2026-08-27'));

        $this->assertSame('2026-08-24', $week['from']);
        $this->assertSame('2026-08-27', $week['to']);
        $this->assertTrue($this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-27'))['is_last']);
    }

    public function test_multiple_holidays_narrow_the_range_from_both_ends(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', ['2026-08-26', '2026-08-27']);

        $week = $this->resolver->resolveWeek($this->moment('2026-08-27'));

        $this->assertSame(TradingWeekResolver::STATUS_OK, $week['status']);
        $this->assertSame('2026-08-26', $week['from']);
        $this->assertSame('2026-08-27', $week['to']);
        $this->assertSame(['2026-08-26', '2026-08-27'], $week['trading_days']);
    }

    public function test_week_with_no_trading_day_is_reported_as_such(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', []);

        $week = $this->resolver->resolveWeek($this->moment('2026-08-27'));

        $this->assertSame(TradingWeekResolver::STATUS_NO_TRADING_DAYS, $week['status']);
        $this->assertNull($week['from']);
        $this->assertNull($week['to']);
        $this->assertFalse($this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-27'))['is_last']);
    }

    public function test_incomplete_calendar_refuses_to_guess_the_last_trading_day(): void
    {
        // Friday and the weekend have no rows. Without this guard Thursday
        // would look like the final trading day and a Monday..Thursday window
        // would be filed as the week's broker summary.
        $this->calendar('2026-08-24', '2026-08-27', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        $week = $this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-27'));

        $this->assertSame(TradingWeekResolver::STATUS_INCOMPLETE, $week['status']);
        $this->assertFalse($week['is_last']);
        $this->assertNull($week['from']);
        $this->assertContains('2026-08-28', $week['missing_dates']);
    }

    public function test_a_weekend_day_still_resolves_its_own_week(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        // Saturday belongs to the Monday-Sunday week that just ended.
        $week = $this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-29'));

        $this->assertSame('2026-08-24', $week['week_start']);
        $this->assertSame('2026-08-30', $week['week_end']);
        $this->assertSame('2026-08-28', $week['to']);
        $this->assertFalse($week['is_last'], 'Saturday is not a trading day, let alone the last one.');
    }

    public function test_a_midweek_day_is_not_the_last_trading_day(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $this->assertFalse($this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-26'))['is_last']);
    }

    public function test_the_final_trading_day_is_recognised(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $week = $this->resolver->describeLastTradingDayOfWeek($this->moment('2026-08-28'));

        $this->assertTrue($week['is_last']);
        $this->assertSame('2026-08-24', $week['from']);
        $this->assertSame('2026-08-28', $week['to']);
    }

    public function test_the_latest_trading_day_is_the_last_one_observed_not_today(): void
    {
        // The calendar stops on Thursday, which is what it looks like on a
        // Friday evening before Yahoo has published the day's bar.
        $this->calendar('2026-08-24', '2026-08-27', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        $latest = $this->resolver->latestTradingDayOnOrBefore($this->moment('2026-08-28'));

        $this->assertNotNull($latest);
        $this->assertSame('2026-08-27', $latest->toDateString());
        $this->assertSame('Asia/Jakarta', $latest->timezoneName);
    }

    public function test_the_latest_trading_day_skips_back_over_a_holiday(): void
    {
        // Friday is a holiday, so Thursday is the last day that traded.
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        $latest = $this->resolver->latestTradingDayOnOrBefore($this->moment('2026-08-29'));

        $this->assertSame('2026-08-27', $latest?->toDateString());
    }

    public function test_a_calendar_that_stopped_long_ago_reports_nothing_recent(): void
    {
        $this->calendar('2026-08-24', '2026-08-28', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        // Two months on, the honest answer is "no recent trading day" rather
        // than a date from August that would have a backfill request months.
        $this->assertNull($this->resolver->latestTradingDayOnOrBefore($this->moment('2026-10-28'), 30));
    }

    public function test_the_next_trading_day_snaps_a_weekend_forward_to_monday(): void
    {
        $this->calendar('2026-08-24', '2026-08-31', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28', '2026-08-31',
        ]);

        // The day after a Friday window is a Saturday. Asking Stockbit for
        // 29..31 August returns Monday's flow filed as a three-day range.
        $next = $this->resolver->nextTradingDayOnOrAfter($this->moment('2026-08-29'));

        $this->assertSame('2026-08-31', $next?->toDateString());
    }

    public function test_the_next_trading_day_returns_a_trading_date_unchanged(): void
    {
        $this->calendar('2026-08-24', '2026-08-28', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $this->assertSame(
            '2026-08-26',
            $this->resolver->nextTradingDayOnOrAfter($this->moment('2026-08-26'))?->toDateString(),
        );
    }

    public function test_today_is_resolved_in_jakarta_not_in_the_application_timezone(): void
    {
        // 2026-08-27 23:30 UTC is already 2026-08-28 06:30 in Jakarta. A
        // resolver reading the server clock would answer with the wrong day
        // and, on a Friday, with the wrong week.
        $this->assertSame('UTC', config('app.timezone'));

        $today = $this->resolver->today(Carbon::parse('2026-08-27 23:30:00', 'UTC'));

        $this->assertSame('2026-08-28', $today->toDateString());
        $this->assertSame('Asia/Jakarta', $today->timezoneName);
    }
}
