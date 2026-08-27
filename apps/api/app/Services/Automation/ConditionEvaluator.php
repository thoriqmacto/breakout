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
        $opportunity = $this->calendar->describeWeeklyOpportunity($moment);

        $metadata = [
            'condition' => ScheduledTask::CONDITION_LAST_TRADING_DAY_OF_WEEK,
            'market_date' => $opportunity['date'],
            'timezone' => $this->calendar->timezone(),
            'week_start' => $opportunity['week_start'],
            'week_end' => $opportunity['week_end'],
            'range_from' => $opportunity['from'],
            'range_to' => $opportunity['to'],
            'trading_days' => $opportunity['trading_days'],
            'weekly_mode' => $opportunity['mode'],
        ];

        if ($opportunity['mode'] === TradingWeekResolver::MODE_CURRENT) {
            return $this->allow($metadata);
        }

        // The week that could not be recognised as it closed. When Friday is a
        // holiday the calendar has no row for it on Thursday, so Thursday
        // cannot know it was the week's last trading day -- and refuses to
        // guess. The new week's opening trading day is the first moment the
        // old week is settled enough to summarise.
        if ($opportunity['mode'] === TradingWeekResolver::MODE_CATCH_UP) {
            return $this->allow($metadata);
        }

        $reason = (string) $opportunity['reason'];

        if ($reason === TradingWeekResolver::STATUS_INCOMPLETE) {
            $metadata['missing_dates'] = $opportunity['missing_dates'];

            return [
                'run' => false,
                'reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'message' => sprintf(
                    'The trading calendar is missing %d day(s) of the week of %s (%s), so today cannot be confirmed as its final trading day. Refresh it with "php artisan automation:trading-calendar-refresh".',
                    count($opportunity['missing_dates']),
                    $opportunity['week_start'],
                    implode(', ', array_slice($opportunity['missing_dates'], 0, 7)),
                ),
                'metadata' => $metadata,
            ];
        }

        if ($reason === TradingWeekResolver::STATUS_NO_TRADING_DAYS) {
            return [
                'run' => false,
                'reason' => TradingWeekResolver::STATUS_NO_TRADING_DAYS,
                'message' => sprintf('The week of %s contains no IDX trading day.', $opportunity['week_start']),
                'metadata' => $metadata,
            ];
        }

        return [
            'run' => false,
            'reason' => 'not_last_trading_day_of_week',
            'message' => sprintf(
                '%s is neither the final trading day of its week nor the first of a new one with an unsummarised week behind it.',
                $opportunity['date'],
            ),
            'metadata' => $metadata,
        ];
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
