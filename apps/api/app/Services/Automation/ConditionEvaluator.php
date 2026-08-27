<?php

namespace App\Services\Automation;

use App\Models\ScheduledTask;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Decides whether a due task should actually execute.
 *
 * A schedule says *when* to consider running; a condition says whether the
 * market makes that meaningful. The daily OHLCV job is due at 16:00 every day
 * of the year, but only executes on a day the IDX actually traded.
 *
 * Every outcome is a decision the run history can show, including "we could
 * not tell". A calendar that cannot answer produces a skip with a named
 * reason, never a guess -- guessing here means scraping the wrong range or
 * calling Stockbit on Christmas Day.
 */
class ConditionEvaluator
{
    public function __construct(private readonly TradingWeekResolver $calendar) {}

    /**
     * @return array{run: bool, reason: ?string, message: ?string, metadata: array<string, mixed>}
     */
    public function evaluate(ScheduledTask $task, Carbon $moment): array
    {
        $condition = (string) ($task->condition ?: ScheduledTask::CONDITION_NONE);

        try {
            return match ($condition) {
                ScheduledTask::CONDITION_TRADING_DAY => $this->tradingDay($moment),
                ScheduledTask::CONDITION_LAST_TRADING_DAY_OF_WEEK => $this->lastTradingDayOfWeek($moment),
                default => $this->allow(['condition' => ScheduledTask::CONDITION_NONE]),
            };
        } catch (Throwable $exception) {
            // The calendar is a database table; if reading it fails, the safe
            // answer is to not run. Scraping "just in case" costs an API quota
            // and can write a window for a date the market was shut.
            return [
                'run' => false,
                'reason' => 'trading_calendar_unavailable',
                'message' => 'The trading calendar could not be read, so the market condition could not be evaluated: '
                    .$exception->getMessage(),
                'metadata' => ['condition' => $condition],
            ];
        }
    }

    /**
     * @return array{run: bool, reason: ?string, message: ?string, metadata: array<string, mixed>}
     */
    private function tradingDay(Carbon $moment): array
    {
        $today = $this->calendar->today($moment);
        $day = $this->calendar->describeDay($today);

        $metadata = [
            'condition' => ScheduledTask::CONDITION_TRADING_DAY,
            'market_date' => $day['date'],
            'timezone' => $this->calendar->timezone(),
        ];

        if (! $day['known']) {
            // No row is not the same as "closed". It means the calendar has
            // not been built this far, and the honest answer is to say so.
            return [
                'run' => false,
                'reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'message' => sprintf(
                    'The trading calendar has no row for %s, so it cannot confirm the market was open. Rebuild it with "php artisan trading-calendar:build".',
                    $day['date'],
                ),
                'metadata' => $metadata,
            ];
        }

        if (! $day['is_trading_day']) {
            return [
                'run' => false,
                'reason' => 'not_trading_day',
                'message' => sprintf('%s is not an IDX trading day.', $day['date']),
                'metadata' => $metadata,
            ];
        }

        return $this->allow($metadata);
    }

    /**
     * @return array{run: bool, reason: ?string, message: ?string, metadata: array<string, mixed>}
     */
    private function lastTradingDayOfWeek(Carbon $moment): array
    {
        $week = $this->calendar->describeLastTradingDayOfWeek($moment);

        $metadata = [
            'condition' => ScheduledTask::CONDITION_LAST_TRADING_DAY_OF_WEEK,
            'market_date' => $week['date'],
            'timezone' => $this->calendar->timezone(),
            'week_start' => $week['week_start'],
            'week_end' => $week['week_end'],
            'range_from' => $week['from'],
            'range_to' => $week['to'],
            'trading_days' => $week['trading_days'],
        ];

        if ($week['status'] === TradingWeekResolver::STATUS_INCOMPLETE) {
            $metadata['missing_dates'] = $week['missing_dates'];

            return [
                'run' => false,
                'reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'message' => sprintf(
                    'The trading calendar is missing %d day(s) of the week of %s (%s), so today cannot be confirmed as its final trading day. Rebuild it with "php artisan trading-calendar:build".',
                    count($week['missing_dates']),
                    $week['week_start'],
                    implode(', ', array_slice($week['missing_dates'], 0, 7)),
                ),
                'metadata' => $metadata,
            ];
        }

        if ($week['status'] === TradingWeekResolver::STATUS_NO_TRADING_DAYS) {
            return [
                'run' => false,
                'reason' => TradingWeekResolver::STATUS_NO_TRADING_DAYS,
                'message' => sprintf('The week of %s contains no IDX trading day.', $week['week_start']),
                'metadata' => $metadata,
            ];
        }

        if (! $week['is_last']) {
            return [
                'run' => false,
                'reason' => 'not_last_trading_day_of_week',
                'message' => sprintf(
                    '%s is not the final trading day of its week; that is %s.',
                    $week['date'],
                    (string) $week['to'],
                ),
                'metadata' => $metadata,
            ];
        }

        return $this->allow($metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{run: bool, reason: ?string, message: ?string, metadata: array<string, mixed>}
     */
    private function allow(array $metadata): array
    {
        return ['run' => true, 'reason' => null, 'message' => null, 'metadata' => $metadata];
    }
}
