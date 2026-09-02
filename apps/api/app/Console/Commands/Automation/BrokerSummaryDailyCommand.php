<?php

namespace App\Console\Commands\Automation;

use App\Console\Commands\Automation\Concerns\FetchesBrokerSummaryWindows;
use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Automation\TradingWeekResolver;
use App\Services\BrokerSummaryArchiveMirror;
use App\Services\BrokerSummaryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily broker summary: bring every asset up to the latest valid trading
 * day, one genuine trading session at a time.
 *
 * The range is resolved per asset, because assets are not equally current.
 * One collected yesterday needs a single day; one that missed a week needs
 * that week; one added last month has no daily history at all.
 *
 * **Every window this command creates is a single trading session.** That is
 * the rule the whole daily pipeline now rests on, and it is a change from how
 * this job first worked. It used to repair a multi-day gap by fetching one
 * aggregate covering the whole of it -- cheaper, and a perfectly valid archive
 * record, but not the same thing. Monday's flow, Tuesday's flow and
 * Wednesday's flow are three observations; their sum over Monday to Wednesday
 * is one. A range aggregate cannot be taken apart into the path through it,
 * so an aggregate filed where daily observations belong silently destroys the
 * accumulation/distribution trajectory it looks like it provides.
 *
 * So a gap is repaired as individual sessions. Tickers missing the same
 * session are grouped, which keeps the invocation count proportional to the
 * number of *dates* rather than to tickers times dates: four hundred tickers
 * missing three sessions is three scrapes, not twelve hundred.
 *
 * `--max-backfill-sessions` bounds what one run will ask for, and the most
 * recent sessions are taken first so an asset keeps moving forward every
 * night rather than crawling out of a long gap oldest-first.
 *
 * An asset with **no** genuine daily history is deliberately not given months
 * of it by a nightly job. It gets a cursor -- the latest confirmed session --
 * and grows a daily series forward from there. Establishing history backwards
 * is an explicit, bounded operation via `--from`, because it is an API budget
 * decision rather than routine maintenance.
 *
 * Existing multi-day aggregates are untouched and remain valid: they are real
 * archive records and good evidence at their own length. The database
 * legitimately holds both, and only `from_date === to_date` windows are ever
 * treated as daily observations.
 *
 * `to` is deliberately the last *observed* trading day and not today. The
 * calendar is derived from published bars, so on a trading afternoon before
 * publication it still ends yesterday; fetching through today anyway would
 * file a partial session as a complete one. The lag is reported as
 * `days_behind` and closes itself on the next run.
 *
 * Nothing here is destructive. A window is keyed on
 * (asset, from_date, to_date, transaction_type), so re-running a session
 * converges on the same row instead of duplicating, and an asset that is
 * already current is skipped without an API call.
 */
class BrokerSummaryDailyCommand extends Command
{
    use FetchesBrokerSummaryWindows;

    protected $signature = 'automation:broker-summary-daily
        {--date= : Treat this date as today (YYYY-MM-DD, default: today in Asia/Jakarta)}
        {--tickers=* : Limit the run to specific tickers}
        {--max-backfill-sessions=5 : Most trading sessions one run will collect per ticker}
        {--from= : Force this start date for every ticker, ignoring what is already stored}
        {--no-import : Scrape and archive without importing}
        {--no-mirror : Skip the Google Drive mirror for this run}
        {--skip-token-check : Do not preflight the Stockbit token (the scheduler has already done it)}';

    protected $description = 'Bring every broker-summary asset up to the latest valid trading day, backfilling whatever each one is behind by.';

    /**
     * How far back the calendar is searched for the latest trading day.
     *
     * Beyond this the calendar has not merely lagged, it has stopped, and the
     * honest answer is "no recent trading day" rather than a date from two
     * months ago that would make every asset request a huge range.
     */
    private const CALENDAR_LOOKBACK_DAYS = 30;

    /**
     * Failures and per-range detail listed by name on the run record. Beyond
     * this the counts still tell the whole story.
     */
    private const MAX_REPORTED = 50;

    /**
     * Hard ceiling on how many sessions one range walk will enumerate.
     *
     * The per-run limit is `--max-backfill-sessions`; this is a guard on the
     * walk itself, so an explicit `--from` years in the past cannot turn into
     * an unbounded loop before the clamp is ever applied.
     */
    private const MAX_SESSION_SCAN = 400;

    public function handle(
        TradingWeekResolver $calendar,
        StockbitTokenHealth $tokenHealth,
        BrokerSummaryImporter $importer,
        BrokerSummaryArchiveMirror $mirror,
        RunMetadata $metadata,
    ): int {
        $startedAt = microtime(true);

        $marketDate = $this->resolveMarketDate($calendar);

        if ($marketDate === null) {
            return self::INVALID;
        }

        $metadata->merge([
            'job' => 'broker_summary_daily',
            'market_date' => $marketDate->toDateString(),
            'timezone' => $calendar->timezone(),
        ]);

        $to = $calendar->latestTradingDayOnOrBefore($marketDate, self::CALENDAR_LOOKBACK_DAYS);

        if ($to === null) {
            // Not a holiday: a calendar that has nothing in the last month.
            // Guessing a date here would send every asset a range built on an
            // assumption, so the run says what is wrong and stops.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'error_summary' => sprintf(
                    'The trading calendar records no trading day in the %d days to %s. Refresh it with "php artisan automation:trading-calendar-refresh".',
                    self::CALENDAR_LOOKBACK_DAYS,
                    $marketDate->toDateString(),
                ),
            ]);

            $this->warn('The trading calendar has no recent trading day, so there is no valid date to collect up to.');

            return self::SUCCESS;
        }

        $daysBehind = max(0, (int) $to->diffInDays($marketDate));

        $metadata->merge([
            'collect_to' => $to->toDateString(),
            'days_behind' => $daysBehind,
            'today_confirmed' => $to->equalTo($marketDate),
        ]);

        if (! $to->equalTo($marketDate)) {
            // Routine early in the evening, before Yahoo publishes. Said
            // plainly so a run that collected "only" up to yesterday is not
            // read as a failure.
            $this->line(sprintf(
                '%s is not yet confirmed as a trading day, so this run collects up to %s -- the latest day the calendar records as traded.',
                $marketDate->toDateString(),
                $to->toDateString(),
            ));
        }

        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $metadata->merge(['skipped' => true, 'skip_reason' => 'no_broker_summary_assets', 'ticker_count' => 0]);
            $this->warn('No assets have sync_broker_summary enabled, so there is nothing to fetch.');

            return self::SUCCESS;
        }

        $metadata->set('ticker_count', count($tickers));

        try {
            $forcedFrom = $this->forcedFrom($calendar->timezone());
        } catch (\Throwable) {
            $this->error('--from must be a YYYY-MM-DD date.');

            return self::INVALID;
        }

        $plan = $this->plan($calendar, $tickers, $to, $forcedFrom);

        $metadata->merge([
            'up_to_date_ticker_count' => count($plan['up_to_date']),
            'backfilled_ticker_count' => count($plan['backfilled']),
            'clamped_ticker_count' => count($plan['clamped']),
            'clamped_tickers' => array_slice($plan['clamped'], 0, self::MAX_REPORTED),
            'cursor_established_count' => count($plan['cursor_established']),
            'cursor_established_tickers' => array_slice($plan['cursor_established'], 0, self::MAX_REPORTED),
            'session_count' => count($plan['groups']),
            // Every entry here is one trading session. The key is named
            // `sessions` rather than `ranges` because a range is exactly what
            // this job no longer produces.
            'sessions' => array_slice(array_map(
                static fn (array $group): array => [
                    'date' => $group['from'],
                    'tickers' => count($group['tickers']),
                ],
                array_values($plan['groups']),
            ), 0, self::MAX_REPORTED),
        ]);

        if ($plan['groups'] === []) {
            $metadata->merge(['skipped' => true, 'skip_reason' => 'already_up_to_date']);
            $this->info(sprintf('Every ticker already holds a window through %s; nothing to fetch.', $to->toDateString()));

            return self::SUCCESS;
        }

        // Preflighted here rather than at the top, so a run with nothing to
        // fetch -- an already-current evening, or a manual run on a quiet day
        // -- succeeds without needing a live token. The scheduler preflights
        // separately before it takes the shared Stockbit lock, so a scheduled
        // run is still blocked before it reaches this point.
        if (! $this->option('skip-token-check')) {
            $preflight = $tokenHealth->preflight();

            if (! $preflight['ok']) {
                $metadata->merge([
                    'blocked_token' => true,
                    'skip_reason' => $preflight['reason'],
                    'error_summary' => $preflight['message'],
                ]);

                $this->error((string) $preflight['message']);

                return self::FAILURE;
            }
        }

        $this->info(sprintf(
            'Collecting through %s: %d ticker(s) across %d trading session(s), %d already current.',
            $to->toDateString(),
            count($tickers) - count($plan['up_to_date']),
            count($plan['groups']),
            count($plan['up_to_date']),
        ));

        if ($plan['cursor_established'] !== []) {
            $this->line(sprintf(
                '  %d ticker(s) have no daily history yet and are starting a daily series at %s. Use --from to establish history backwards deliberately.',
                count($plan['cursor_established']),
                $to->toDateString(),
            ));
        }

        $exitCode = self::SUCCESS;
        $written = [];
        $missing = [];

        foreach ($plan['groups'] as $group) {
            $this->line(sprintf(
                '  %s for %d ticker(s)',
                $group['from'],
                count($group['tickers']),
            ));

            $result = $this->scrapeWindow($group['tickers'], $group['from'], $group['to']);

            if ($result !== self::SUCCESS && $exitCode === self::SUCCESS) {
                $exitCode = $result;
            }

            $expected = $this->expectedPaths($group['tickers'], $group['from'], $group['to']);
            $produced = $this->existingPaths($expected);

            $written = array_merge($written, $produced);
            $missing = array_merge($missing, array_values(array_diff($expected, $produced)));
        }

        $metadata->merge([
            'window_files_expected' => count($written) + count($missing),
            'window_files_written' => count($written),
            'failed_ticker_count' => count($missing),
            'failed_tickers' => array_slice(array_map(
                fn (string $path): string => $this->symbolOfPath($path),
                $missing,
            ), 0, self::MAX_REPORTED),
            'partial' => $missing !== [],
        ]);

        if ($missing !== []) {
            $metadata->set('error_summary', sprintf(
                '%d of %d ticker(s) produced no broker-summary file for the range they were due.',
                count($missing),
                count($written) + count($missing),
            ));

            $this->warn(sprintf('%d ticker(s) produced no archive file.', count($missing)));
        }

        if ($written === []) {
            $this->error('The scrape produced no broker-summary files, so there is nothing to import or mirror.');

            return self::FAILURE;
        }

        $this->importWindows($importer, $written, $metadata);
        $this->mirrorArchive($mirror, $written, $metadata);

        $metadata->merge([
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            // The archive mirror above is this job's own, and the scrape
            // mirrored any CSVs it touched. Nothing is left for the runner.
            'mirror_handled' => true,
        ]);

        return $exitCode;
    }

    /**
     * Work out what each ticker is missing, and group tickers that need the
     * same range so they can be fetched together.
     *
     * Grouping matters at this scale: in the steady state every ticker needs
     * the same single day, which is one scrape invocation rather than four
     * hundred.
     *
     * @param  array<int, string>  $tickers
     * @return array{
     *     groups: array<string, array{from: string, to: string, tickers: array<int, string>}>,
     *     up_to_date: array<int, string>,
     *     backfilled: array<int, string>,
     *     clamped: array<int, string>
     * }
     */
    private function plan(TradingWeekResolver $calendar, array $tickers, Carbon $to, ?Carbon $forced): array
    {
        $latestDaily = $this->latestStoredSingleDay($tickers, $calendar->timezone());
        $maxSessions = max(1, (int) $this->option('max-backfill-sessions'));

        $groups = [];
        $upToDate = [];
        $backfilled = [];
        $clamped = [];
        $cursorEstablished = [];

        foreach ($tickers as $ticker) {
            $cursor = $latestDaily[$ticker] ?? null;

            if ($forced !== null) {
                // An explicit start date is a deliberate, bounded historical
                // backfill. It is still collected as individual sessions --
                // the point of the option is to obtain daily history, not to
                // obtain one long aggregate faster.
                $from = $forced->copy();
            } elseif ($cursor === null) {
                // No genuine daily history. A nightly job must not decide on
                // its own to request months of it, so the asset starts a
                // daily series at the latest confirmed session and grows
                // forward from there.
                $from = $to->copy();
                $cursorEstablished[] = $ticker;
            } else {
                $from = $cursor->copy()->addDay();
            }

            $sessions = $this->sessionsBetween($calendar, $from, $to);

            if ($sessions === []) {
                $upToDate[] = $ticker;

                continue;
            }

            if (count($sessions) > $maxSessions) {
                // Most recent first: an asset behind by a month is more
                // useful current-with-a-hole than complete-but-stale, and the
                // remaining sessions are collected on subsequent runs.
                $sessions = array_slice($sessions, -$maxSessions);
                $clamped[] = $ticker;
            }

            if (count($sessions) > 1 || ! $sessions[0]->equalTo($to)) {
                $backfilled[] = $ticker;
            }

            foreach ($sessions as $session) {
                $date = $session->toDateString();

                // Grouped by session, so the invocation count follows the
                // number of dates rather than tickers times dates.
                $groups[$date] ??= ['from' => $date, 'to' => $date, 'tickers' => []];
                $groups[$date]['tickers'][] = $ticker;
            }
        }

        // Oldest session first, so the archive is written in the order it is
        // read and a partial run leaves a contiguous prefix.
        ksort($groups);

        return [
            'groups' => $groups,
            'up_to_date' => $upToDate,
            'backfilled' => $backfilled,
            'clamped' => $clamped,
            'cursor_established' => $cursorEstablished,
        ];
    }

    /**
     * The trading sessions in a closed range, oldest first.
     *
     * Walks the calendar rather than the clock: a weekend or an IDX holiday
     * is not a session, and requesting one would file an empty or misdated
     * response as though the market had traded.
     *
     * @return array<int, Carbon>
     */
    private function sessionsBetween(TradingWeekResolver $calendar, Carbon $from, Carbon $to): array
    {
        if ($from->greaterThan($to)) {
            return [];
        }

        $sessions = [];
        $cursor = $calendar->nextTradingDayOnOrAfter($from, self::CALENDAR_LOOKBACK_DAYS);

        // A guard on the walk itself rather than on the calendar: a range
        // measured in years would otherwise loop for as long as it takes.
        $limit = self::MAX_SESSION_SCAN;

        while ($cursor !== null && ! $cursor->greaterThan($to) && $limit-- > 0) {
            $sessions[] = $cursor->copy();
            $cursor = $calendar->nextTradingDayOnOrAfter($cursor->copy()->addDay(), self::CALENDAR_LOOKBACK_DAYS);
        }

        return $sessions;
    }

    /**
     * The newest *single-day* window already stored for each ticker.
     *
     * Deliberately single-day only, and this is the change that makes the
     * rest of the command work. The cursor used to be the newest window of
     * any shape, so an asset holding a three-month aggregate ending last
     * Friday looked current and was never given daily observations at all --
     * the aggregate suppressed the very collection that would have produced
     * the daily series. Asking specifically what daily history exists means
     * an asset with only aggregates correctly reports none.
     *
     * Constrained to the configured transaction type: a different type is a
     * different aggregate, not a different slice of the same one.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, Carbon>
     */
    private function latestStoredSingleDay(array $tickers, string $timezone): array
    {
        $assetIds = Asset::query()
            ->whereIn('symbol', $tickers)
            ->pluck('id', 'symbol')
            ->all();

        if ($assetIds === []) {
            return [];
        }

        $transactionType = (string) config('stockbit.defaults.transaction_type');

        $rows = BrokerSummaryWindow::query()
            ->whereIn('asset_id', array_values($assetIds))
            ->whereColumn('from_date', '=', 'to_date')
            ->when($transactionType !== '', fn ($query) => $query->where('transaction_type', $transactionType))
            ->selectRaw('asset_id, MAX(to_date) as latest_to')
            ->groupBy('asset_id')
            ->pluck('latest_to', 'asset_id')
            ->all();

        $latest = [];

        foreach ($assetIds as $symbol => $assetId) {
            $value = $rows[$assetId] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            try {
                $latest[$symbol] = Carbon::parse((string) $value, $timezone)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return $latest;
    }

    private function forcedFrom(string $timezone): ?Carbon
    {
        $option = $this->option('from');

        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        return $this->marketDate(trim($option), $timezone);
    }

    /**
     * A date string as a midnight in the market's own timezone.
     */
    private function marketDate(string $value, string $timezone): Carbon
    {
        return Carbon::parse($value, $timezone)->startOfDay();
    }

    private function resolveMarketDate(TradingWeekResolver $calendar): ?Carbon
    {
        $option = $this->option('date');

        if (is_string($option) && trim($option) !== '') {
            try {
                return Carbon::parse(trim($option), $calendar->timezone())->startOfDay();
            } catch (\Throwable) {
                $this->error('--date must be a YYYY-MM-DD date.');

                return null;
            }
        }

        return $calendar->today();
    }
}
