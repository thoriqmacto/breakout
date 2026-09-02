<?php

namespace App\Services\Reconciliation;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryWindow;
use App\Services\CsvBars;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Rebuilds an asset's canonical state from its reconciliation document.
 *
 * The fast recovery path. `broker-summary:rebuild` remains the forensic one --
 * it re-reads every archived response and re-derives everything from the
 * original bytes -- and it is the right tool when the question is "what did
 * Stockbit actually say?". It is the wrong tool for standing a server back up,
 * because it costs one parse per file across years of history.
 *
 * Validation happens before any write, per asset. A document that is corrupt,
 * truncated, or written under a schema version this code does not understand
 * fails that asset outright: a partial restore is worse than a failed one,
 * because it looks like it worked. Assets are independent, so one bad document
 * does not stop the rest -- it is named in the report instead.
 *
 * The aggregate rule survives the round trip. A window is restored with the
 * range it was stored with, so a three-month aggregate comes back as a
 * three-month aggregate and never as ninety fabricated days.
 */
class ReconciliationRestorer
{
    public function __construct(private readonly ReconciliationStore $store) {}

    /**
     * @param  array{
     *   symbols?: array<int, string>|null,
     *   disk?: string|null,
     *   dry_run?: bool,
     *   skip_csv?: bool,
     * }  $options
     * @return array<string, mixed>
     */
    public function restore(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $skipCsv = (bool) ($options['skip_csv'] ?? false);
        $source = $this->source($options['disk'] ?? null);

        $manifest = $this->readManifest($source);

        if ($manifest === null) {
            return [
                'ok' => false,
                'dry_run' => $dryRun,
                'source' => $source['label'],
                'error' => 'No reconciliation manifest is available at the requested source, so there is nothing to restore from.',
                'assets' => [],
                'restored' => [],
                'failed' => [],
            ];
        }

        $available = array_keys(is_array($manifest['assets'] ?? null) ? $manifest['assets'] : []);
        $requested = $options['symbols'] ?? null;

        $symbols = $requested === null || $requested === []
            ? $available
            : array_values(array_intersect($available, array_map('strtoupper', $requested)));

        sort($symbols);

        $restored = [];
        $failed = [];
        $details = [];

        foreach ($symbols as $symbol) {
            try {
                $document = $this->readDocument($source, $symbol);
                $this->validate($symbol, $document, $manifest);

                $detail = $dryRun
                    ? $this->describe($document)
                    : $this->apply($document, $skipCsv);

                $details[$symbol] = $detail;
                $restored[] = $symbol;
            } catch (Throwable $exception) {
                $failed[$symbol] = $exception->getMessage();
            }
        }

        return [
            'ok' => $failed === [],
            'dry_run' => $dryRun,
            'source' => $source['label'],
            'manifest_generated_at' => $manifest['generated_at'] ?? null,
            'manifest_schema_version' => $manifest['schema_version'] ?? null,
            'requested' => count($symbols),
            'restored' => $restored,
            'failed' => $failed,
            'assets' => $details,
            'missing_from_manifest' => $requested === null || $requested === []
                ? []
                : array_values(array_diff(array_map('strtoupper', $requested), $available)),
        ];
    }

    /**
     * Everything that must be true before a single row is written.
     *
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>  $manifest
     */
    private function validate(string $symbol, ?array $document, array $manifest): void
    {
        if ($document === null) {
            throw new RuntimeException('The reconciliation document is missing.');
        }

        $expected = (int) config('reconciliation.schema_version', 1);
        $actual = $document['schema_version'] ?? null;

        if (! is_int($actual)) {
            throw new RuntimeException('The reconciliation document has no schema_version.');
        }

        // Refused rather than best-effort. A newer document may carry fields
        // this code would drop, and dropping fields during a disaster
        // recovery is how data is lost while a command reports success.
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Schema version %d is not supported by this build, which reads version %d.',
                $actual,
                $expected,
            ));
        }

        if (strtoupper((string) ($document['symbol'] ?? '')) !== $symbol) {
            throw new RuntimeException(sprintf(
                'The document is for %s, not %s.',
                (string) ($document['symbol'] ?? '?'),
                $symbol,
            ));
        }

        foreach (['coverage', 'ohlcv', 'broker_summary'] as $key) {
            if (! array_key_exists($key, $document)) {
                throw new RuntimeException(sprintf('The document is missing its "%s" section.', $key));
            }
        }

        if (! is_array($document['ohlcv']) || ! is_array($document['broker_summary']['windows'] ?? null)) {
            throw new RuntimeException('The document\'s OHLCV or broker windows are malformed.');
        }

        // The manifest is the commit marker, so a document whose bytes no
        // longer match the hash it was published under is not the document
        // that was committed.
        $claimed = $manifest['assets'][$symbol]['hash'] ?? null;

        if (is_string($claimed) && isset($document['__raw_hash']) && $document['__raw_hash'] !== $claimed) {
            throw new RuntimeException('The document does not match the hash recorded in the manifest.');
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function apply(array $document, bool $skipCsv): array
    {
        $symbol = strtoupper((string) $document['symbol']);
        $assetInfo = is_array($document['asset'] ?? null) ? $document['asset'] : [];

        $asset = Asset::firstOrNew(['symbol' => $symbol]);
        $asset->name = $assetInfo['name'] ?? $asset->name ?? $symbol;
        $asset->sector = $assetInfo['sector'] ?? $asset->sector;
        $asset->sync_price = (bool) ($assetInfo['sync_price'] ?? true);
        $asset->sync_broker_summary = (bool) ($assetInfo['sync_broker_summary'] ?? true);
        $asset->save();

        $bars = $this->restoreBars($asset, $document['ohlcv']);
        $csv = $skipCsv ? null : $this->restoreCsv($symbol, $document['ohlcv']);
        $broker = $this->restoreWindows($asset, $document['broker_summary']['windows']);

        return [
            'asset_id' => (int) $asset->id,
            'ohlcv_rows' => $bars,
            'csv_path' => $csv,
            'windows' => $broker['windows'],
            'entries' => $broker['entries'],
            'detectors' => $broker['detectors'],
            'single_day_windows' => $broker['single_day'],
            'aggregate_windows' => $broker['aggregate'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $ohlcv
     */
    private function restoreBars(Asset $asset, array $ohlcv): int
    {
        if ($ohlcv === []) {
            return 0;
        }

        $now = Carbon::now();
        $written = 0;

        foreach (array_chunk($ohlcv, 500) as $chunk) {
            $payload = [];

            foreach ($chunk as $bar) {
                $payload[] = [
                    'asset_id' => $asset->id,
                    'date' => (string) $bar['date'],
                    'open' => $bar['open'],
                    'high' => $bar['high'],
                    'low' => $bar['low'],
                    'close' => $bar['close'],
                    'volume' => $bar['volume'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('price_bars')->upsert(
                $payload,
                ['asset_id', 'date'],
                ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
            );

            $written += count($payload);
        }

        return $written;
    }

    /**
     * Rewrite the seed CSV the rest of the application reads.
     *
     * Through CsvBars, not a second writer: the header, the d/m/Y date format
     * and the atomic rename are already defined there, and a restore that
     * produced a subtly different CSV would be discovered by whatever read it
     * next rather than here.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     */
    private function restoreCsv(string $symbol, array $ohlcv): string
    {
        $path = rtrim((string) config('csv.seed_dir'), '/').'/'.$symbol.'.csv';

        $rows = [];

        foreach ($ohlcv as $bar) {
            $rows[(string) $bar['date']] = [
                'open' => $bar['open'],
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => $bar['close'],
                'volume' => $bar['volume'],
            ];
        }

        CsvBars::write($path, $rows);

        return $path;
    }

    /**
     * @param  array<int, array<string, mixed>>  $windows
     * @return array{windows: int, entries: int, detectors: int, single_day: int, aggregate: int}
     */
    private function restoreWindows(Asset $asset, array $windows): array
    {
        $counts = ['windows' => 0, 'entries' => 0, 'detectors' => 0, 'single_day' => 0, 'aggregate' => 0];

        foreach ($windows as $window) {
            $from = (string) ($window['from_date'] ?? '');
            $to = (string) ($window['to_date'] ?? '');

            if ($from === '' || $to === '' || $from > $to) {
                throw new RuntimeException(sprintf('A broker window has an unusable range (%s..%s).', $from, $to));
            }

            $coverage = is_array($window['coverage'] ?? null) ? $window['coverage'] : [];

            $model = BrokerSummaryWindow::updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'from_date' => $from,
                    'to_date' => $to,
                    'transaction_type' => (string) ($window['transaction_type'] ?? ''),
                ],
                [
                    'market_board' => $window['market_board'] ?? null,
                    'investor_type' => $window['investor_type'] ?? null,
                    'requested_limit' => $window['requested_limit'] ?? null,
                    'returned_buyer_count' => $coverage['returned_buyer_count'] ?? null,
                    'returned_seller_count' => $coverage['returned_seller_count'] ?? null,
                    'total_buyer' => $coverage['total_buyer'] ?? null,
                    'total_seller' => $coverage['total_seller'] ?? null,
                    'source_filename' => $window['source_filename'] ?? null,
                    'source_hash' => $window['source_hash'] ?? null,
                    'imported_at' => $window['imported_at'] ?? null,
                ],
            );

            $counts['windows']++;
            $from === $to ? $counts['single_day']++ : $counts['aggregate']++;

            // Replace rather than merge, matching the importer: a restore is
            // the authoritative view of that window, and a broker that has
            // since dropped off the list must not survive it.
            $model->entries()->delete();

            $entries = is_array($window['entries'] ?? null) ? $window['entries'] : [];

            foreach (array_chunk($entries, 500) as $chunk) {
                $model->entries()->createMany($chunk);
                $counts['entries'] += count($chunk);
            }

            $detector = $window['bandar_detector'] ?? null;

            if (is_array($detector)) {
                BandarDetectorSummary::updateOrCreate(
                    [
                        'asset_id' => $asset->id,
                        'from_date' => $from,
                        'to_date' => $to,
                        'transaction_type' => (string) ($window['transaction_type'] ?? ''),
                    ],
                    [
                        'broker_summary_window_id' => $model->id,
                        'broker_accdist' => $detector['broker_accdist'] ?? null,
                        'number_broker_buysell' => $detector['number_broker_buysell'] ?? null,
                        'total_buyer' => $detector['total_buyer'] ?? null,
                        'total_seller' => $detector['total_seller'] ?? null,
                        'value' => $detector['value'] ?? null,
                        'volume' => $detector['volume'] ?? null,
                        'average_price' => $detector['average_price'] ?? null,
                        'metrics_json' => $detector['metrics_json'] ?? null,
                    ],
                );

                $counts['detectors']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function describe(array $document): array
    {
        $coverage = $document['coverage'];
        $windows = $document['broker_summary']['windows'];

        $single = 0;

        foreach ($windows as $window) {
            if (($window['is_single_day'] ?? false) === true) {
                $single++;
            }
        }

        return [
            'ohlcv_rows' => count($document['ohlcv']),
            'ohlcv_first' => $coverage['ohlcv']['first_date'] ?? null,
            'ohlcv_last' => $coverage['ohlcv']['last_date'] ?? null,
            'windows' => count($windows),
            'single_day_windows' => $single,
            'aggregate_windows' => count($windows) - $single,
        ];
    }

    /**
     * Where documents are read from: a remote disk, or the local layer.
     *
     * @return array{disk: ?Filesystem, label: string}
     */
    private function source(?string $disk): array
    {
        if ($disk === null || trim($disk) === '' || $disk === (string) config('reconciliation.local_disk', 'local')) {
            return ['disk' => null, 'label' => 'local'];
        }

        return ['disk' => Storage::disk($disk), 'label' => $disk];
    }

    /**
     * @param  array{disk: ?Filesystem, label: string}  $source
     * @return array<string, mixed>|null
     */
    private function readManifest(array $source): ?array
    {
        $contents = $this->readRaw($source, $this->store->manifestPath());

        if ($contents === null) {
            return null;
        }

        try {
            return $this->store->decode($contents);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  array{disk: ?Filesystem, label: string}  $source
     * @return array<string, mixed>|null
     */
    private function readDocument(array $source, string $symbol): ?array
    {
        $contents = $this->readRaw($source, $this->store->assetPath($symbol));

        if ($contents === null) {
            return null;
        }

        try {
            $document = $this->store->decode($contents);
        } catch (JsonException $exception) {
            throw new RuntimeException('The reconciliation document is not valid JSON: '.$exception->getMessage());
        }

        // Carried alongside rather than inside the schema, so the hash check
        // compares the bytes that were actually read.
        $document['__raw_hash'] = hash('sha256', $contents);

        return $document;
    }

    /**
     * @param  array{disk: ?Filesystem, label: string}  $source
     */
    private function readRaw(array $source, string $path): ?string
    {
        if ($source['disk'] === null) {
            return $this->store->read($path);
        }

        try {
            if (! $source['disk']->exists($path)) {
                return null;
            }

            $contents = $source['disk']->get($path);

            return is_string($contents) ? $contents : null;
        } catch (Throwable) {
            return null;
        }
    }
}
