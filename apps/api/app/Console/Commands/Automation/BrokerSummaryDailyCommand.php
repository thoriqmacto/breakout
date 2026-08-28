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
 * day, whatever it is currently behind by.
 *
 * The range is resolved per asset rather than globally, because assets are not
 * equally current. One added last month has nothing stored; one imported as a
 * three-month aggregate is current to the end of that aggregate; one collected
 * yesterday needs a single day. Asking a single question -- "what has this
 * asset not got yet?" -- gives all three the right range without a separate
 * backfill mode to remember to run.
 *
 *   from = the first trading day after that asset's newest stored window
 *   to   = the latest day the calendar positively records as having traded
 *
 * In the steady state those are the same date, so the window is a single day
 * and reaches broker_summary_facts and broksums like any daily import. After
 * a gap they are not, and the gap is fetched as one aggregate covering it --
 * one request rather than one per missing session, which is the difference
 * between a backfill that completes tonight and one that spends a week of API
 * budget. A range aggregate is stored as exactly what it is, and every
 * consumer already reads windows by range.
 *
 * `to` is deliberately the last *observed* trading day and not today. The
 * calendar is derived from published bars, so on a trading afternoon before
 * publication it still ends yesterday; fetching through today anyway would
 * file a partial session as a complete one. The lag is reported as
 * `days_behind` and closes itself on the next run.
 *
 * Nothing here is destructive. A window is keyed on
 * (asset, from_date, to_date, transaction_type), so re-running a range
 * converges on the same row instead of duplicating, and an asset that is
 * already current is skipped without an API call.
 */
class BrokerSummaryDailyCommand extends Command
{
    use FetchesBrokerSummaryWindows;

    protected $signature = 'automation:broker-summary-daily
        {--date= : Treat this date as today (YYYY-MM-DD, default: today in Asia/Jakarta)}
        {--tickers=* : Limit the run to specific tickers}
        {--max-backfill-days=120 : Longest gap one run will request as a single aggregate}
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
            'ranges' => array_slice(array_map(
                static fn (array $group): array => [
                    'from' => $group['from'],
                    'to' => $group['to'],
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
            'Collecting through %s: %d ticker(s) across %d range(s), %d already current.',
            $to->toDateString(),
            count($tickers) - count($plan['up_to_date']),
            count($plan['groups']),
            count($plan['up_to_date']),
        ));

        $exitCode = self::SUCCESS;
        $written = [];
        $missing = [];

        foreach ($plan['groups'] as $group) {
            $this->line(sprintf(
                '  %s → %s for %d ticker(s)%s',
                $group['from'],
                $group['to'],
                count($group['tickers']),
                $group['from'] === $group['to'] ? '' : ' (backfill)',
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
        $latest = $this->latestStoredEnd($tickers, $calendar->timezone());
        $maxBackfill = max(1, (int) $this->option('max-backfill-days'));
        $floor = $to->copy()->subDays($maxBackfill);

        $groups = [];
        $upToDate = [];
        $backfilled = [];
        $clamped = [];

        foreach ($tickers as $ticker) {
            $stored = $latest[$ticker] ?? null;

            if ($forced !== null) {
                $from = $forced->copy();
            } elseif ($stored === null) {
                // Nothing stored at all. One bounded aggregate gives the asset
                // a usable history immediately; anything longer is a decision
                // for broker-summary:rebuild or an explicit --from.
                $from = $floor->copy();
            } else {
                $from = $stored->copy()->addDay();
            }

            // The clamp is a safety valve on what the job decides for itself,
            // so an explicit --from is exempt: someone asking for a specific
            // range by hand means it.
            if ($forced === null && $from->lessThan($floor)) {
                // The gap is longer than one run is willing to ask for in a
                // single aggregate. Taking the most recent slice of it keeps
                // the asset moving forward every night rather than requesting
                // a range Stockbit may refuse outright.
                $from = $floor->copy();
                $clamped[] = $ticker;
            }

            // A resumed backfill almost always restarts on a Saturday. Asking
            // for 29..31 August returns Monday's flow filed as a three-day
            // range, which is then not a single-day window and never reaches
            // the daily projections -- so snap to the session itself.
            $from = $calendar->nextTradingDayOnOrAfter($from, self::CALENDAR_LOOKBACK_DAYS) ?? $from;

            if ($from->greaterThan($to)) {
                $upToDate[] = $ticker;

                continue;
            }

            if (! $from->equalTo($to)) {
                $backfilled[] = $ticker;
            }

            $key = $from->toDateString().'|'.$to->toDateString();

            $groups[$key] ??= ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'tickers' => []];
            $groups[$key]['tickers'][] = $ticker;
        }

        // Oldest range first, so a long backfill lands before the day that
        // follows it and the archive is written in the order it is read.
        ksort($groups);

        return [
            'groups' => $groups,
            'up_to_date' => $upToDate,
            'backfilled' => $backfilled,
            'clamped' => $clamped,
        ];
    }

    /**
     * The newest window end date already stored for each ticker.
     *
     * Constrained to the configured transaction type: a different type is a
     * different aggregate, not a different slice of the same one, so a window
     * of another type says nothing about what this one is missing.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, Carbon>
     */
    private function latestStoredEnd(array $tickers, string $timezone): array
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

            // Read in the market's timezone so it lines up with the dates
            // this command compares it against. Parsed as a UTC midnight
            // instead, a stored 26 August sits seven hours *after* the Jakarta
            // midnight of the 27th, and the asset reports itself up to date on
            // the very day it is missing.
            $latest[strtoupper((string) $symbol)] = $this->marketDate((string) $value, $timezone);
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
