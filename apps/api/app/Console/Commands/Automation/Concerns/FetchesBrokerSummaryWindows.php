<?php

namespace App\Console\Commands\Automation\Concerns;

use App\Services\Automation\RunMetadata;
use App\Services\BrokerSummaryArchiveMirror;
use App\Services\BrokerSummaryImporter;
use App\Support\AssetList;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The scrape/import/mirror mechanics shared by the weekly and daily
 * broker-summary jobs.
 *
 * Both jobs do the same four things -- ask `stockbit:scrape` for a range,
 * work out which archive files that should have produced, import exactly
 * those, and mirror them -- and differ only in how they decide the range.
 * Keeping one implementation means a fix to the import or the mirror cannot
 * land in one job and be missed in the other.
 *
 * The commands using this must declare --no-import, --no-mirror and
 * --tickers.
 */
trait FetchesBrokerSummaryWindows
{
    /**
     * Fetch one range for a set of tickers.
     *
     * @param  array<int, string>  $tickers
     */
    protected function scrapeWindow(array $tickers, string $from, string $to): int
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
     * The archive paths a range should have produced.
     *
     * Deterministic: the scraper names each file
     * SYMBOL_from_to_TRANSACTIONTYPE.json, so the files a run produced are
     * known without walking the directory or trusting a timestamp.
     *
     * @param  array<int, string>  $tickers
     * @return array<int, string>
     */
    protected function expectedPaths(array $tickers, string $from, string $to): array
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
    protected function existingPaths(array $paths): array
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
     * The symbol a produced-or-missing archive path belongs to.
     */
    protected function symbolOfPath(string $path): string
    {
        return strtoupper((string) explode('_', basename($path))[0]);
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function importWindows(BrokerSummaryImporter $importer, array $paths, RunMetadata $metadata): void
    {
        if ($this->option('no-import')) {
            $metadata->set('import', ['status' => 'skipped']);
            $this->line('Import skipped (--no-import).');

            return;
        }

        try {
            // Only this run's files. Re-running the same range converges rather
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
    protected function mirrorArchive(BrokerSummaryArchiveMirror $mirror, array $paths, RunMetadata $metadata): void
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
     * The tickers this run covers: the --tickers override, or every asset with
     * sync_broker_summary enabled.
     *
     * @return array<int, string>
     */
    protected function resolveTickers(): array
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
