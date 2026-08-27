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

    /** Today closes its own week; summarise the week that is ending. */
    public const MODE_CURRENT = 'current';

    /** Today opens a new week; summarise the previous one, which is now settled. */
    public const MODE_CATCH_UP = 'catch_up';

    /** No weekly summary is due. */
    public const MODE_NONE = 'none';

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
     * Trading dates recorded so far in the Monday-Sunday week containing $date.
     *
     * Unlike resolveWeek(), this does not require the week to be complete: it
     * reports the rows that exist. Used to answer "is today the first trading
     * day of this week", which is knowable as soon as today has a row even
     * though the rest of the week is still in the future.
     *
     * @return array<int, string>
     */
    public function knownTradingDaysThisWeek(?Carbon $date = null): array
    {
        $day = $this->today($date);
        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $day->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        return TradingCalendarDay::query()
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->where('is_trading_day', true)
            ->orderBy('date')
            ->get()
            ->map(static fn (TradingCalendarDay $row): string => Carbon::parse($row->date)->toDateString())
            ->all();
    }

    /**
     * Whether a weekly summary should run right now, and for which week.
     *
     * There are two moments at which it should, and the second one exists
     * because the calendar can only ever look backwards.
     *
     * The normal case is the week's final trading day: on Friday, with the
     * whole week settled, `to` is today and the week can be summarised as it
     * closes.
     *
     * The catch-up case covers the week that could not be recognised in time.
     * When Friday is a holiday, Thursday is the week's last trading day -- but
     * standing on Thursday the calendar has no row for Friday yet, so it
     * cannot know that, and refuses to guess. That week would otherwise never
     * be summarised at all. Once the new week's first trading day arrives, the
     * old week is fully settled and can be summarised retrospectively.
     *
     * Nothing here predicts the future: both cases are decided from rows that
     * already exist.
     *
     * @return array{
     *     mode: string, from: ?string, to: ?string, reason: ?string, date: string,
     *     week_start: string, week_end: string, trading_days: array<int, string>,
     *     missing_dates: array<int, string>
     * }
     */
    public function describeWeeklyOpportunity(?Carbon $date = null): array
    {
        $day = $this->today($date);
        $current = $this->resolveWeek($day);

        $base = [
            'date' => $day->toDateString(),
            'week_start' => $current['week_start'],
            'week_end' => $current['week_end'],
            'trading_days' => $current['trading_days'],
            'missing_dates' => $current['missing_dates'],
        ];

        // The week has closed and today is the day it closed on.
        if ($current['status'] === self::STATUS_OK && $current['to'] === $day->toDateString()) {
            return $base + [
                'mode' => self::MODE_CURRENT,
                'from' => $current['from'],
                'to' => $current['to'],
                'reason' => null,
            ];
        }

        $catchUp = $this->describeCatchUp($day);

        if ($catchUp !== null) {
            return $base + $catchUp;
        }

        // Nothing to run. Report the current week's own reason, which is the
        // one that explains today rather than a week that is already handled.
        $reason = match ($current['status']) {
            self::STATUS_INCOMPLETE => self::STATUS_INCOMPLETE,
            self::STATUS_NO_TRADING_DAYS => self::STATUS_NO_TRADING_DAYS,
            default => 'not_last_trading_day_of_week',
        };

        return $base + ['mode' => self::MODE_NONE, 'from' => null, 'to' => null, 'reason' => $reason];
    }

    /**
     * The previous week, when today is the first trading day of the new one
     * and that previous week is now fully settled.
     *
     * @return array{mode: string, from: ?string, to: ?string, reason: ?string}|null
     */
    private function describeCatchUp(Carbon $day): ?array
    {
        $todayRow = $this->describeDay($day);

        // Today must itself be a confirmed trading day. On a holiday or a
        // weekend there is no reason to reach back.
        if (! $todayRow['known'] || ! $todayRow['is_trading_day']) {
            return null;
        }

        $known = $this->knownTradingDaysThisWeek($day);

        // Only the week's opening trading day catches up, so a Wednesday does
        // not keep re-proposing a week that Monday already handled.
        if (($known[0] ?? null) !== $day->toDateString()) {
            return null;
        }

        $previous = $this->resolveWeek($day->copy()->subWeek());

        // A previous week that is still incomplete cannot be summarised
        // honestly, and one with no trading days has nothing to summarise.
        if ($previous['status'] !== self::STATUS_OK) {
            return null;
        }

        return [
            'mode' => self::MODE_CATCH_UP,
            'from' => $previous['from'],
            'to' => $previous['to'],
            'reason' => null,
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
