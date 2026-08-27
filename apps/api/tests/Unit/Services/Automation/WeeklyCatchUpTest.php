<?php

namespace Tests\Unit\Services\Automation;

use App\Models\TradingCalendarDay;
use App\Services\Automation\TradingWeekResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The catch-up exists because the calendar can only look backwards.
 *
 * When Friday is a holiday, Thursday is the week's last trading day -- but
 * standing on Thursday there is no row for Friday yet, so the resolver
 * correctly refuses to call Thursday the last trading day. Without a catch-up
 * that week would simply never be summarised.
 */
class WeeklyCatchUpTest extends TestCase
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
     * Write calendar rows for a date range, marking the given dates as trading
     * days. Dates outside the range simply have no row, which is how the
     * refresh leaves anything the market has not settled yet.
     *
     * @param  array<int, string>  $tradingDates
     */
    private function calendar(string $from, string $to, array $tradingDates): void
    {
        for ($cursor = Carbon::parse($from); $cursor->lessThanOrEqualTo(Carbon::parse($to)); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $isWeekend = $cursor->dayOfWeekIso >= 6;

            TradingCalendarDay::query()->updateOrCreate(['date' => $date], [
                'is_trading_day' => in_array($date, $tradingDates, true),
                'is_weekend' => $isWeekend,
                'is_holiday' => ! $isWeekend && ! in_array($date, $tradingDates, true),
            ]);
        }
    }

    private function at(string $date): Carbon
    {
        return Carbon::parse($date.' 18:00', 'Asia/Jakarta');
    }

    public function test_a_normal_friday_summarises_the_week_that_is_closing(): void
    {
        $this->calendar('2026-08-24', '2026-08-30', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
        ]);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-28'));

        $this->assertSame(TradingWeekResolver::MODE_CURRENT, $opportunity['mode']);
        $this->assertSame('2026-08-24', $opportunity['from']);
        $this->assertSame('2026-08-28', $opportunity['to']);
    }

    public function test_thursday_still_refuses_when_friday_is_unknown(): void
    {
        // The calendar has settled only through Thursday. Friday might trade
        // or might be a holiday; nothing here can tell.
        $this->calendar('2026-08-24', '2026-08-27', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-27'));

        $this->assertSame(TradingWeekResolver::MODE_NONE, $opportunity['mode']);
        $this->assertSame(TradingWeekResolver::STATUS_INCOMPLETE, $opportunity['reason']);
    }

    public function test_the_next_weeks_first_trading_day_catches_up_a_holiday_shortened_week(): void
    {
        // Friday 28 August was a holiday. The whole previous week plus the new
        // Monday are now settled, so the old week can be summarised.
        $this->calendar('2026-08-24', '2026-08-31', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-31',
        ]);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-31'));

        $this->assertSame(TradingWeekResolver::MODE_CATCH_UP, $opportunity['mode']);
        $this->assertSame('2026-08-24', $opportunity['from']);
        $this->assertSame('2026-08-27', $opportunity['to'], 'The range must end on Thursday, not the Friday holiday.');
    }

    public function test_only_the_weeks_opening_trading_day_catches_up(): void
    {
        $this->calendar('2026-08-24', '2026-09-01', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-31', '2026-09-01',
        ]);

        // Tuesday must not keep re-proposing a week Monday already handled.
        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-09-01'));

        $this->assertSame(TradingWeekResolver::MODE_NONE, $opportunity['mode']);
        $this->assertNull($opportunity['from'], 'The previous week must not be proposed again.');
        $this->assertNull($opportunity['to']);
    }

    public function test_a_monday_holiday_makes_tuesday_the_catch_up_day(): void
    {
        // Previous week closed short on Thursday, and the new week opens on
        // Tuesday because Monday was itself a holiday.
        $this->calendar('2026-08-24', '2026-09-01', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-09-01',
        ]);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-09-01'));

        $this->assertSame(TradingWeekResolver::MODE_CATCH_UP, $opportunity['mode']);
        $this->assertSame('2026-08-27', $opportunity['to']);
    }

    public function test_a_non_trading_day_never_catches_up(): void
    {
        $this->calendar('2026-08-24', '2026-08-31', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);

        // Saturday: not a trading day, so there is no reason to reach back.
        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-29'));

        $this->assertSame(TradingWeekResolver::MODE_NONE, $opportunity['mode']);
    }

    public function test_catch_up_is_refused_while_the_previous_week_is_still_unsettled(): void
    {
        // Monday of the new week is settled, but the previous week is missing
        // its Friday row entirely — so its true last trading day is unknown.
        // The new week is settled only through Monday, which is how the
        // refresh actually leaves it.
        $this->calendar('2026-08-24', '2026-08-27', [
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27',
        ]);
        $this->calendar('2026-08-31', '2026-08-31', ['2026-08-31']);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-31'));

        $this->assertSame(TradingWeekResolver::MODE_NONE, $opportunity['mode']);
    }

    public function test_a_week_with_no_trading_days_is_not_caught_up(): void
    {
        $this->calendar('2026-08-24', '2026-08-31', ['2026-08-31']);

        $opportunity = $this->resolver->describeWeeklyOpportunity($this->at('2026-08-31'));

        $this->assertSame(TradingWeekResolver::MODE_NONE, $opportunity['mode']);
    }

    public function test_known_trading_days_this_week_does_not_require_a_complete_week(): void
    {
        $this->calendar('2026-08-24', '2026-08-26', ['2026-08-24', '2026-08-25', '2026-08-26']);

        $this->assertSame(
            ['2026-08-24', '2026-08-25', '2026-08-26'],
            $this->resolver->knownTradingDaysThisWeek($this->at('2026-08-26')),
        );
    }
}
