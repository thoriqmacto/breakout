<?php

namespace App\Console\Commands\Automation;

use App\Console\Commands\Automation\Concerns\FetchesBrokerSummaryWindows;
use App\Models\BrokerSummaryWindow;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Automation\TradingWeekResolver;
use App\Services\BrokerSummaryArchiveMirror;
use App\Services\BrokerSummaryImporter;
use Illuminate\Console\Command;

/**
 * One aggregate broker-summary window covering a whole IDX trading week.
 *
 * No longer part of the seeded schedule: broker summaries are now collected
 * daily by automation:broker-summary-daily, which gives the same coverage at
 * one-day granularity. This is kept because a week is still a useful unit to
 * ask for deliberately, and because a weekly row already in scheduled_tasks
 * must keep working.
 *
 * The range is resolved from `trading_calendar`, never from the shape of the
 * week: a Monday holiday moves `from` to Tuesday and a Friday holiday moves
 * `to` to Thursday, and a week whose calendar is incomplete produces no run at
 * all rather than a plausible-looking wrong one.
 *
 * The response Stockbit returns for from..to is one aggregate over that whole
 * range. It is stored as exactly that -- a multi-day BrokerSummaryWindow --
 * and never decomposed into per-day rows stamped with Monday's or Friday's
 * date, which would present a week of flow as a single session's.
 *
 * Import is targeted. The files this run produced are known by name, so they
 * are imported directly instead of re-reading the whole archive; that keeps a
 * Friday evening from getting slower every week. `broker-summary:rebuild` is
 * untouched and remains the full-archive recovery path.
 */
class BrokerSummaryWeeklyCommand extends Command
{
    use FetchesBrokerSummaryWindows;

    protected $signature = 'automation:broker-summary-weekly
        {--from= : Week start override (YYYY-MM-DD)}
        {--to= : Week end override (YYYY-MM-DD)}
        {--tickers=* : Limit the run to specific tickers}
        {--no-import : Scrape and archive without importing}
        {--no-mirror : Skip the Google Drive mirror for this run}
        {--force : Run even when today is not the final trading day of its week}
        {--skip-token-check : Do not preflight the Stockbit token (the scheduler has already done it)}';

    protected $description = 'Fetch, import and mirror one aggregate broker-summary window for the current trading week.';

    public function handle(
        TradingWeekResolver $calendar,
        StockbitTokenHealth $tokenHealth,
        BrokerSummaryImporter $importer,
        BrokerSummaryArchiveMirror $mirror,
        RunMetadata $metadata,
    ): int {
        $startedAt = microtime(true);

        $range = $this->resolveRange($calendar, $metadata);

        if ($range === null) {
            return self::SUCCESS;
        }

        [$from, $to] = $range;

        $metadata->merge([
            'job' => 'broker_summary_weekly',
            'range_from' => $from,
            'range_to' => $to,
            'timezone' => $calendar->timezone(),
        ]);

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

        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $metadata->merge(['skipped' => true, 'skip_reason' => 'no_broker_summary_assets', 'ticker_count' => 0]);
            $this->warn('No assets have sync_broker_summary enabled, so there is nothing to fetch.');

            return self::SUCCESS;
        }

        $metadata->set('ticker_count', count($tickers));

        $this->info(sprintf(
            'Fetching the broker summary window %s → %s for %d ticker(s).',
            $from,
            $to,
            count($tickers),
        ));

        $exitCode = $this->scrapeWindow($tickers, $from, $to);

        // Deterministic: the scraper names each archive file
        // SYMBOL_from_to_TRANSACTIONTYPE.json, so the files this run produced
        // are known without walking the directory or trusting a timestamp.
        $expected = $this->expectedPaths($tickers, $from, $to);
        $written = $this->existingPaths($expected);
        $missing = array_values(array_diff($expected, $written));

        $metadata->merge([
            'window_files_expected' => count($expected),
            'window_files_written' => count($written),
            'failed_ticker_count' => count($missing),
            'failed_tickers' => array_slice(array_map(
                fn (string $path): string => $this->symbolOfPath($path),
                $missing,
            ), 0, 50),
            'partial' => $missing !== [],
        ]);

        if ($missing !== []) {
            $metadata->set('error_summary', sprintf(
                '%d of %d ticker(s) produced no broker-summary file for %s..%s.',
                count($missing),
                count($expected),
                $from,
                $to,
            ));

            $this->warn(sprintf('%d ticker(s) produced no archive file for this window.', count($missing)));
        }

        if ($written === []) {
            $this->error('The scrape produced no broker-summary files, so there is nothing to import or mirror.');

            return self::FAILURE;
        }

        $this->importWindows($importer, $written, $metadata);
        $this->mirrorArchive($mirror, $written, $metadata);

        $metadata->merge([
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            // The archive mirror below is this job's own, and the scrape
            // mirrored any CSVs it touched. Nothing is left for the runner.
            'mirror_handled' => true,
        ]);

        return $exitCode === self::SUCCESS ? self::SUCCESS : $exitCode;
    }

    /**
     * The week to summarise, or null when none is due.
     *
     * Normally this is the week that is closing today. It may also be the
     * previous week, caught up on the first trading day of a new one: when
     * Friday is a holiday, Thursday is the week's last trading day, but
     * standing on Thursday the calendar has no row for Friday yet and refuses
     * to guess -- so that week is summarised retrospectively rather than not
     * at all.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolveRange(TradingWeekResolver $calendar, RunMetadata $metadata): ?array
    {
        $fromOption = $this->option('from');
        $toOption = $this->option('to');

        if (is_string($fromOption) && $fromOption !== '' && is_string($toOption) && $toOption !== '') {
            if ($fromOption > $toOption) {
                $this->error('--from must not be after --to.');

                return null;
            }

            return [$fromOption, $toOption];
        }

        $opportunity = $calendar->describeWeeklyOpportunity();

        $metadata->merge([
            'week_start' => $opportunity['week_start'],
            'week_end' => $opportunity['week_end'],
            'market_date' => $opportunity['date'],
            'trading_days' => $opportunity['trading_days'],
            'weekly_mode' => $opportunity['mode'],
        ]);

        if ($opportunity['mode'] === TradingWeekResolver::MODE_NONE) {
            return $this->refuse($opportunity, $metadata);
        }

        $from = (string) $opportunity['from'];
        $to = (string) $opportunity['to'];

        // A catch-up only makes sense if the week was in fact never done. The
        // normal Friday path is left alone: re-running it deliberately is a
        // supported way to repair a bad import, and the importer is idempotent.
        if ($opportunity['mode'] === TradingWeekResolver::MODE_CATCH_UP
            && ! $this->option('force')
            && $this->alreadySummarised($from, $to)
        ) {
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'week_already_summarised',
            ]);

            $this->line(sprintf('The week %s → %s is already summarised; nothing to catch up.', $from, $to));

            return null;
        }

        if ($opportunity['mode'] === TradingWeekResolver::MODE_CATCH_UP) {
            $this->warn(sprintf(
                'Catching up the week %s → %s, which closed on a day the calendar could not confirm at the time.',
                $from,
                $to,
            ));
        }

        return [$from, $to];
    }

    /**
     * Explain a non-run in the calendar's own terms.
     *
     * @param  array<string, mixed>  $opportunity
     * @return null
     */
    private function refuse(array $opportunity, RunMetadata $metadata)
    {
        $reason = (string) $opportunity['reason'];

        if ($reason === TradingWeekResolver::STATUS_INCOMPLETE) {
            // The most dangerous case, and the reason this check exists: with
            // Friday's row absent, Thursday looks like the last trading day of
            // the week and a Monday..Thursday window would be filed as the
            // week's summary.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'missing_dates' => $opportunity['missing_dates'],
                'error_summary' => sprintf(
                    'The trading calendar is missing %d day(s) of the week starting %s.',
                    count($opportunity['missing_dates']),
                    $opportunity['week_start'],
                ),
            ]);

            $this->warn(sprintf(
                'The trading calendar is incomplete for the week of %s (missing %s). Refusing to guess this week\'s final trading day; refresh it with "php artisan automation:trading-calendar-refresh".',
                $opportunity['week_start'],
                implode(', ', array_slice($opportunity['missing_dates'], 0, 7)),
            ));

            return null;
        }

        if ($reason === TradingWeekResolver::STATUS_NO_TRADING_DAYS) {
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => TradingWeekResolver::STATUS_NO_TRADING_DAYS,
            ]);

            $this->warn(sprintf('The week of %s has no IDX trading day; nothing to summarise.', $opportunity['week_start']));

            return null;
        }

        if ($this->option('force')) {
            $this->error('--force needs an explicit --from and --to when no week is due.');

            return null;
        }

        $metadata->merge([
            'skipped' => true,
            'skip_reason' => 'not_last_trading_day_of_week',
        ]);

        $this->warn(sprintf(
            '%s is neither the final trading day of its week nor the first of a new one with an unsummarised week behind it. Pass --from and --to to run a specific week.',
            $opportunity['date'],
        ));

        return null;
    }

    /**
     * Whether this exact window has already been imported.
     *
     * Asks the canonical record rather than the run history: a window keyed on
     * (asset, from_date, to_date, transaction_type) is the actual product
     * outcome, and survives the task being renamed, recreated, or run by hand.
     */
    private function alreadySummarised(string $from, string $to): bool
    {
        return BrokerSummaryWindow::query()
            ->whereDate('from_date', $from)
            ->whereDate('to_date', $to)
            ->exists();
    }
}
