<?php

namespace App\Console\Commands\Automation;

use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Automation\TradingWeekResolver;
use App\Services\BrokerSummaryArchiveMirror;
use App\Services\BrokerSummaryImporter;
use App\Support\AssetList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The weekly broker summary, run at 16:00 WIB on the final valid trading day
 * of each IDX week.
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

        $exitCode = $this->scrape($tickers, $from, $to);

        // Deterministic: the scraper names each archive file
        // SYMBOL_from_to_TRANSACTIONTYPE.json, so the files this run produced
        // are known without walking the directory or trusting a timestamp.
        $expected = $this->expectedPaths($tickers, $from, $to);
        $written = $this->existing($expected);
        $missing = array_values(array_diff($expected, $written));

        $metadata->merge([
            'window_files_expected' => count($expected),
            'window_files_written' => count($written),
            'failed_ticker_count' => count($missing),
            'failed_tickers' => array_slice(array_map(
                static fn (string $path): string => strtoupper((string) explode('_', basename($path))[0]),
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
     * The Monday..Sunday week's first and last valid trading dates, or the
     * explicit override.
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

        $week = $calendar->describeLastTradingDayOfWeek();

        $metadata->merge([
            'week_start' => $week['week_start'],
            'week_end' => $week['week_end'],
            'market_date' => $week['date'],
            'trading_days' => $week['trading_days'],
        ]);

        if ($week['status'] === TradingWeekResolver::STATUS_INCOMPLETE) {
            // The most dangerous case, and the reason this check exists: with
            // Friday's row absent, Thursday looks like the last trading day of
            // the week and a Monday..Thursday window would be filed as the
            // week's summary.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => TradingWeekResolver::STATUS_INCOMPLETE,
                'missing_dates' => $week['missing_dates'],
                'error_summary' => sprintf(
                    'The trading calendar is missing %d day(s) of the week starting %s.',
                    count($week['missing_dates']),
                    $week['week_start'],
                ),
            ]);

            $this->warn(sprintf(
                'The trading calendar is incomplete for the week of %s (missing %s). Refusing to guess this week\'s final trading day; rebuild it with "php artisan trading-calendar:build".',
                $week['week_start'],
                implode(', ', array_slice($week['missing_dates'], 0, 7)),
            ));

            return null;
        }

        if ($week['status'] === TradingWeekResolver::STATUS_NO_TRADING_DAYS) {
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => TradingWeekResolver::STATUS_NO_TRADING_DAYS,
            ]);

            $this->warn(sprintf('The week of %s has no IDX trading day; nothing to summarise.', $week['week_start']));

            return null;
        }

        if (! $week['is_last'] && ! $this->option('force')) {
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'not_last_trading_day_of_week',
            ]);

            $this->warn(sprintf(
                '%s is not the final trading day of its week (%s is). Pass --force to run anyway.',
                $week['date'],
                (string) $week['to'],
            ));

            return null;
        }

        return [(string) $week['from'], (string) $week['to']];
    }

    /**
     * @param  array<int, string>  $tickers
     */
    private function scrape(array $tickers, string $from, string $to): int
    {
        $parameters = [
            '--market-detector' => true,
            '--from' => $from,
            '--to' => $to,
            '--no-profile-sync' => true,
        ];

        // --all is the documented invocation and is used verbatim whenever the
        // broker-summary setting excludes nothing. When it does exclude
        // something, the narrowed list is passed instead, so a muted asset is
        // not fetched only for the importer to discard it.
        if ($tickers === AssetList::symbols()) {
            $parameters['--all'] = true;
        } else {
            $parameters['tickers'] = $tickers;
        }

        if ($this->option('no-mirror')) {
            $original = Config::get('csv.mirror_disk');
            Config::set('csv.mirror_disk', null);

            try {
                return Artisan::call('stockbit:scrape', $parameters, $this->getOutput());
            } finally {
                Config::set('csv.mirror_disk', $original);
            }
        }

        return Artisan::call('stockbit:scrape', $parameters, $this->getOutput());
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function importWindows(BrokerSummaryImporter $importer, array $paths, RunMetadata $metadata): void
    {
        if ($this->option('no-import')) {
            $metadata->set('import', ['status' => 'skipped']);
            $this->line('Import skipped (--no-import).');

            return;
        }

        try {
            // Only this run's files. Re-running the same week converges rather
            // than duplicating: a window is keyed on
            // (asset, from_date, to_date, transaction_type) and its entries are
            // replaced wholesale.
            $result = $importer->importPaths($paths, (string) config('stockbit.save_disk', 'local'));

            $metadata->set('import', [
                'status' => 'ok',
                'files' => $result['file_count'],
                'imported' => count($result['imported']),
                'skipped' => count($result['skipped']),
                'rows' => $result['row_count'],
                'symbols' => count($result['symbols']),
            ]);

            $this->info(sprintf(
                'Imported %d of %d broker-summary file(s) covering %d symbol(s).',
                count($result['imported']),
                $result['file_count'],
                count($result['symbols']),
            ));
        } catch (Throwable $exception) {
            // The archive on disk is intact; the import can be retried or
            // recovered with broker-summary:rebuild.
            $metadata->merge([
                'import' => ['status' => 'failed', 'message' => $exception->getMessage()],
                'error_summary' => 'The broker-summary import failed: '.$exception->getMessage(),
            ]);

            $this->error('Import failed: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function mirrorArchive(BrokerSummaryArchiveMirror $mirror, array $paths, RunMetadata $metadata): void
    {
        if ($this->option('no-mirror')) {
            $metadata->set('gdrive_broker_summary', ['status' => 'skipped']);

            return;
        }

        if (! $mirror->enabled()) {
            $metadata->set('gdrive_broker_summary', ['status' => 'not_configured']);
            $this->line('No broker-summary mirror disk is configured; the JSON stays local only.');

            return;
        }

        try {
            // Mirrored after the import, so cold storage only ever receives
            // JSON that has already been safely written and read back.
            $result = $mirror->mirror($paths);
            $summary = $mirror->summarize($result);

            $metadata->set('gdrive_broker_summary', $summary);

            if ($result['failed'] !== []) {
                // Reported, never fatal: the local archive is the source of
                // truth and is untouched by a failed upload.
                $metadata->set('error_summary', trim(sprintf(
                    '%s %d broker-summary file(s) failed to reach Google Drive; the local copies are intact.',
                    (string) $metadata->get('error_summary', ''),
                    count($result['failed']),
                )));

                $this->warn(sprintf(
                    '%d file(s) failed to upload to [%s]. The local JSON is intact.',
                    count($result['failed']),
                    (string) $result['disk'],
                ));

                return;
            }

            $this->info(sprintf(
                'Mirrored %d file(s) to [%s], %d already up to date.',
                count($result['uploaded']),
                (string) $result['disk'],
                count($result['skipped_unchanged']),
            ));
        } catch (Throwable $exception) {
            $metadata->set('gdrive_broker_summary', ['status' => 'failed', 'message' => $exception->getMessage()]);
            $this->warn('The Google Drive mirror failed: '.$exception->getMessage().' The local JSON is intact.');
        }
    }

    /**
     * The archive paths this window should have produced.
     *
     * @param  array<int, string>  $tickers
     * @return array<int, string>
     */
    private function expectedPaths(array $tickers, string $from, string $to): array
    {
        $directory = trim((string) config('stockbit.save_dir', 'broker_summary'), '/');
        $transactionType = config('stockbit.defaults.transaction_type');
        $transactionType = is_string($transactionType) && $transactionType !== '' ? $transactionType : 'default';

        return array_map(
            static fn (string $ticker): string => sprintf(
                '%s/%s_%s_%s_%s.json',
                $directory,
                $ticker,
                $from,
                $to,
                $transactionType,
            ),
            $tickers,
        );
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function existing(array $paths): array
    {
        $disk = Storage::disk((string) config('stockbit.save_disk', 'local'));

        return array_values(array_filter($paths, static function (string $path) use ($disk): bool {
            try {
                return $disk->exists($path);
            } catch (Throwable) {
                return false;
            }
        }));
    }

    /**
     * @return array<int, string>
     */
    private function resolveTickers(): array
    {
        /** @var array<int, string> $option */
        $option = $this->option('tickers') ?: [];

        $tickers = $option !== [] ? $option : AssetList::brokerSummarySymbols();

        $normalized = [];

        foreach ($tickers as $ticker) {
            $symbol = strtoupper(trim((string) $ticker));

            if ($symbol !== '') {
                $normalized[$symbol] = $symbol;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }
}
