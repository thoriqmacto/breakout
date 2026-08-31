<?php

namespace App\Console\Commands\Automation;

use App\Models\TradingCalendarDay;
use App\Models\TradingDay;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\TradingWeekResolver;
use App\Services\TradingDayWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Keeps `trading_calendar` current, which every trading-day condition reads.
 *
 * Without this the calendar only advances when someone remembers to rebuild it
 * by hand, and because a missing row is treated as "unknown" rather than
 * "closed", the daily and weekly automations quietly skip every single day.
 *
 * The one rule this command exists to enforce:
 *
 *   Never write a calendar row for a date beyond the last day Yahoo actually
 *   has a bar for.
 *
 * TradingCalendarBuilder derives its answer by absence -- a weekday with no
 * `trading_days` row becomes `is_holiday = true`. That is correct for a date
 * the market has already traded past, and badly wrong for one it has not
 * reached yet. Rebuilding through "today" at any point before Yahoo publishes
 * today's bar would positively record today as a holiday, and the conditions
 * would then skip with a confident `not_trading_day` instead of an honest
 * `trading_calendar_incomplete`. A wrong answer that looks right is the one
 * outcome this whole calendar design exists to avoid, so the range is clamped
 * to the last observed trading date and never guesses past it.
 *
 * The consequence is that the calendar always trails the market by however
 * long Yahoo takes to publish. That lag is reported as `days_behind` rather
 * than hidden.
 */
class TradingCalendarRefreshCommand extends Command
{
    protected $signature = 'automation:trading-calendar-refresh
        {--lookback=45 : Days of history to re-import and rebuild}
        {--from= : Explicit start date (YYYY-MM-DD), overriding --lookback}
        {--skip-import : Rebuild from the existing trading_days rows without calling Yahoo}';

    protected $description = 'Refresh trading_days from Yahoo and rebuild trading_calendar up to the last observed trading day.';

    /**
     * How far the calendar may trail the market before it is worth saying so.
     *
     * A weekend plus a long holiday is legitimately several days; a fortnight
     * means the Yahoo import has been failing and every market-day condition
     * is skipping as a result.
     */
    private const STALE_AFTER_DAYS = 5;

    public function handle(TradingWeekResolver $calendar, RunMetadata $metadata, TradingDayWriter $writer): int
    {
        $today = $calendar->today();

        $from = $this->resolveFrom($today);

        if ($from === null) {
            return self::INVALID;
        }

        $metadata->merge([
            'job' => 'trading_calendar_refresh',
            'market_date' => $today->toDateString(),
            'timezone' => $calendar->timezone(),
            'requested_from' => $from->toDateString(),
        ]);

        // A failed Yahoo import is recorded as partial rather than fatal, so
        // the return value is deliberately not gated on: the calendar is still
        // rebuilt from whatever is already stored, which is the useful half.
        $this->importTradingDays($from, $today, $metadata);

        $lastObserved = $this->lastObservedTradingDate($calendar->timezone());

        if ($lastObserved === null) {
            // Nothing to derive a calendar from. Building one anyway would mark
            // every weekday in range a holiday.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'no_trading_days',
                'error_summary' => 'trading_days is empty, so no calendar can be derived. Run "php artisan trading-days:build" first.',
            ]);

            $this->error('trading_days holds no rows, so there is nothing to derive a calendar from.');

            return self::FAILURE;
        }

        // Whole calendar days between two same-timezone midnights. Signed in
        // Carbon 3, and a seeded database could in principle hold a date ahead
        // of today, so the floor keeps this a lag rather than a negative
        // number nobody would know how to read.
        $daysBehind = max(0, (int) $lastObserved->diffInDays($today));

        $metadata->merge([
            'last_observed_trading_date' => $lastObserved->toDateString(),
            'days_behind' => $daysBehind,
        ]);

        // The clamp. `to` is the last date the market has demonstrably traded
        // through, never today and never a date Yahoo has not reached.
        $to = $lastObserved->lessThan($today) ? $lastObserved : $today;

        if ($to->lessThan($from)) {
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'nothing_settled_in_range',
                'error_summary' => sprintf(
                    'The last observed trading day (%s) is before the requested start (%s), so there is nothing settled to rebuild.',
                    $lastObserved->toDateString(),
                    $from->toDateString(),
                ),
            ]);

            $this->warn('Nothing in the requested range has settled yet; the calendar is unchanged.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Rebuilding the calendar %s → %s (clamped to the last observed trading day).',
            $from->toDateString(),
            $to->toDateString(),
        ));

        try {
            Artisan::call('trading-calendar:build', [
                '--from' => $from->toDateString(),
                '--to' => $to->toDateString(),
            ], $this->getOutput());
        } catch (Throwable $exception) {
            $metadata->merge([
                'calendar_build' => ['status' => 'failed', 'message' => $exception->getMessage()],
                'error_summary' => 'The calendar rebuild failed: '.$exception->getMessage(),
            ]);

            $this->error('The calendar rebuild failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $covered = TradingCalendarDay::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->count();

        $metadata->merge([
            'calendar_from' => $from->toDateString(),
            'calendar_to' => $to->toDateString(),
            'calendar_rows' => $covered,
            'calendar_build' => ['status' => 'ok'],
            'today_covered' => $to->greaterThanOrEqualTo($today),
        ]);

        $this->line(sprintf('The calendar now covers %d day(s) across that range.', $covered));

        $this->reportIncompleteCloses($writer, $from, $to, $metadata);

        if ($to->lessThan($today)) {
            // Expected most of the day: the market has not closed, or Yahoo has
            // not published yet. Said plainly so a skipped OHLCV run later is
            // not a mystery.
            $this->line(sprintf(
                'Today (%s) has no settled bar yet, so it has deliberately not been written. Market-day conditions will report the calendar as incomplete until it does.',
                $today->toDateString(),
            ));
        }

        if ($daysBehind > self::STALE_AFTER_DAYS) {
            $message = sprintf(
                'The calendar is %d days behind (last observed trading day: %s). Every market-day condition will skip until this catches up.',
                $daysBehind,
                $lastObserved->toDateString(),
            );

            $metadata->merge(['partial' => true, 'error_summary' => trim(
                (string) $metadata->get('error_summary', '').' '.$message
            )]);

            $this->warn($message);
        }

        return self::SUCCESS;
    }

    /**
     * Report sessions whose close the market has traded past but we still do
     * not know.
     *
     * A trading date and a complete observation of it are different facts. The
     * row's existence records that the session happened, which is what every
     * market-day condition reads and what the calendar is derived from -- so a
     * missing close must never make the day look like a holiday. But it does
     * make the market data incomplete, and a run that leaves such a row behind
     * is partial rather than clean: an unknown close that nothing surfaces is
     * how one survived weeks of repair attempts.
     */
    private function reportIncompleteCloses(TradingDayWriter $writer, Carbon $from, Carbon $to, RunMetadata $metadata): void
    {
        $incomplete = $writer->incompleteDates($from->toDateString(), $to->toDateString());

        $metadata->merge([
            'null_close_count' => count($incomplete),
            'null_close_dates' => array_slice($incomplete, 0, max(1, (int) config('trading_days.max_reported_dates', 20))),
        ]);

        if ($incomplete === []) {
            return;
        }

        $message = sprintf(
            'The IHSG close is still unknown for %d session(s) in %s..%s: %s. These remain valid trading days.',
            count($incomplete),
            $from->toDateString(),
            $to->toDateString(),
            implode(', ', array_slice($incomplete, 0, max(1, (int) config('trading_days.max_reported_dates', 20)))),
        );

        $metadata->merge(['partial' => true, 'error_summary' => trim(
            (string) $metadata->get('error_summary', '').' '.$message
        )]);

        $this->warn($message);
    }

    /**
     * Pull recent trading days from Yahoo.
     *
     * A failure here is reported but not fatal: the rows already in
     * `trading_days` are still worth rebuilding the calendar from, and losing
     * that because the network was down would be a worse outcome than a
     * calendar that is briefly a day or two behind.
     */
    private function importTradingDays(Carbon $from, Carbon $today, RunMetadata $metadata): void
    {
        if ($this->option('skip-import')) {
            $metadata->set('trading_days_import', ['status' => 'skipped']);
            $this->line('Yahoo import skipped (--skip-import).');

            return;
        }

        try {
            Artisan::call('trading-days:build', [
                '--from' => $from->toDateString(),
                '--to' => $today->toDateString(),
            ], $this->getOutput());

            $metadata->set('trading_days_import', ['status' => 'ok']);
        } catch (Throwable $exception) {
            $metadata->merge([
                'trading_days_import' => ['status' => 'failed', 'message' => $exception->getMessage()],
                'partial' => true,
                'error_summary' => 'The Yahoo trading-day import failed: '.$exception->getMessage(),
            ]);

            $this->warn('The Yahoo trading-day import failed: '.$exception->getMessage());
            $this->line('Rebuilding the calendar from the trading days already stored.');
        }
    }

    /**
     * The most recent date the market is known to have traded.
     */
    private function lastObservedTradingDate(string $timezone): ?Carbon
    {
        $max = TradingDay::query()->max('date');

        if ($max === null || $max === '') {
            return null;
        }

        try {
            // Read in the market's timezone so it lines up with `today`, which
            // is a Jakarta midnight. Parsing this as a UTC midnight instead
            // leaves the two seven hours apart, which silently costs a day off
            // every comparison and off `days_behind`.
            return Carbon::parse((string) $max, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveFrom(Carbon $today): ?Carbon
    {
        $explicit = $this->option('from');

        if (is_string($explicit) && trim($explicit) !== '') {
            try {
                return Carbon::parse(trim($explicit))->startOfDay();
            } catch (Throwable) {
                $this->error('--from must be a YYYY-MM-DD date.');

                return null;
            }
        }

        $lookback = (int) $this->option('lookback');

        if ($lookback < 1) {
            $this->error('--lookback must be a positive number of days.');

            return null;
        }

        return $today->copy()->subDays($lookback)->startOfDay();
    }
}
