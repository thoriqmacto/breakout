<?php

namespace App\Services\Automation;

use App\Models\TradingCalendarDay;
use Illuminate\Support\Carbon;

/**
 * Answers the two market-calendar questions the scheduler asks, always in
 * Asia/Jakarta.
 *
 * Both are answered from `trading_calendar` and never from the shape of the
 * week. "Friday" is not a synonym for "the last trading day": a Friday
 * holiday makes Thursday the last one, and a public holiday on Monday makes
 * Tuesday the first. Guessing either would send the weekly broker-summary
 * scrape at the wrong time or over the wrong range, and the aggregate window
 * it produces would then be silently wrong.
 *
 * When the calendar cannot answer -- because the rows for the rest of the week
 * are simply not there -- this says so rather than assuming. A missing row is
 * indistinguishable from a holiday to any query, so treating "no row" as "not
 * a trading day" would make today look like the last trading day of the week
 * every time the calendar fell behind.
 */
class TradingWeekResolver
{
    /** The calendar covers the whole week and the answer is trustworthy. */
    public const STATUS_OK = 'ok';

    /** Rows are missing for part of the week; no conclusion can be drawn. */
    public const STATUS_INCOMPLETE = 'trading_calendar_incomplete';

    /** The week is fully described and contains no trading day at all. */
    public const STATUS_NO_TRADING_DAYS = 'no_trading_days_in_week';

    public function timezone(): string
    {
        return (string) config('automation.timezone', 'Asia/Jakarta');
    }

    /**
     * "Today" as the Jakarta market sees it, regardless of the server clock's
     * own timezone.
     */
    public function today(?Carbon $now = null): Carbon
    {
        return ($now ? $now->copy() : Carbon::now())
            ->setTimezone($this->timezone())
            ->startOfDay();
    }

    /**
     * Whether the calendar positively records this date as a trading day.
     *
     * A date with no row is not a trading day *and* not a holiday -- it is
     * unknown, and the caller is told which so it can skip for the honest
     * reason.
     *
     * @return array{is_trading_day: bool, known: bool, date: string}
     */
    public function describeDay(Carbon $date): array
    {
        $dateString = $date->toDateString();

        $row = TradingCalendarDay::query()
            ->whereDate('date', $dateString)
            ->first();

        return [
            'date' => $dateString,
            'known' => $row !== null,
            'is_trading_day' => $row !== null && (bool) $row->is_trading_day,
        ];
    }

    public function isTradingDay(Carbon $date): bool
    {
        return $this->describeDay($date)['is_trading_day'];
    }

    /**
     * The first and last valid trading dates of the Monday-Sunday week that
     * contains $date, in Asia/Jakarta.
     *
     * @return array{
     *     status: string,
     *     week_start: string,
     *     week_end: string,
     *     from: ?string,
     *     to: ?string,
     *     trading_days: array<int, string>,
     *     missing_dates: array<int, string>
     * }
     */
    public function resolveWeek(?Carbon $date = null): array
    {
        $day = $this->today($date);

        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $day->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        // Compared as dates rather than as strings. The builder upserts plain
        // "YYYY-MM-DD" values while an Eloquent write of the same model stores
        // "YYYY-MM-DD 00:00:00", and a BETWEEN over raw strings silently drops
        // the final day of the week in the second case -- which reads as an
        // incomplete calendar and would stop the weekly job every week.
        $rows = TradingCalendarDay::query()
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->orderBy('date')
            ->get();

        $known = [];

        foreach ($rows as $row) {
            $known[Carbon::parse($row->date)->toDateString()] = (bool) $row->is_trading_day;
        }

        $missing = [];
        $tradingDays = [];

        for ($cursor = $weekStart->copy(); $cursor->lessThanOrEqualTo($weekEnd); $cursor->addDay()) {
            $dateString = $cursor->toDateString();

            if (! array_key_exists($dateString, $known)) {
                $missing[] = $dateString;

                continue;
            }

            if ($known[$dateString]) {
                $tradingDays[] = $dateString;
            }
        }

        $base = [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'trading_days' => $tradingDays,
            'missing_dates' => $missing,
        ];

        if ($missing !== []) {
            // Refuse to answer rather than answer from half a week. This is
            // the case where a wrong answer is most tempting and most costly:
            // a Thursday looks like the week's last trading day the moment
            // Friday's row is absent.
            return $base + ['status' => self::STATUS_INCOMPLETE, 'from' => null, 'to' => null];
        }

        if ($tradingDays === []) {
            return $base + ['status' => self::STATUS_NO_TRADING_DAYS, 'from' => null, 'to' => null];
        }

        return $base + [
            'status' => self::STATUS_OK,
            'from' => $tradingDays[0],
            'to' => $tradingDays[count($tradingDays) - 1],
        ];
    }

    /**
     * Whether $date is the final valid trading day of its own trading week.
     *
     * @return array{
     *     is_last: bool, status: string, from: ?string, to: ?string,
     *     date: string, week_start: string, week_end: string,
     *     trading_days: array<int, string>, missing_dates: array<int, string>
     * }
     */
    public function describeLastTradingDayOfWeek(?Carbon $date = null): array
    {
        $day = $this->today($date);
        $week = $this->resolveWeek($day);

        return $week + [
            'date' => $day->toDateString(),
            'is_last' => $week['status'] === self::STATUS_OK && $week['to'] === $day->toDateString(),
        ];
    }
}
