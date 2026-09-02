<?php

namespace App\Services\Reconciliation;

use App\Models\Asset;
use App\Services\Automation\TradingWeekResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Orchestrates a reconciliation pass.
 *
 * The shape of a run is: work out cheaply what changed, rebuild only that,
 * then write an index describing everything. Each step is separable so a
 * failure in one asset does not stop the rest, and so a dry run can answer
 * "what would change?" without writing anything.
 *
 * Idempotence is the property to protect. Running this twice with no new
 * market data must rewrite nothing, upload nothing, and leave every hash
 * where it was -- otherwise the mirror does a full re-upload nightly and the
 * incremental design buys nothing. Two independent mechanisms enforce it: the
 * fingerprint decides whether to rebuild, and the store refuses to rewrite a
 * file whose bytes already match.
 *
 * The manifest is written last and always, even when no asset changed,
 * because it also carries the fleet-level health summary the dashboard reads
 * and that can move without any document moving.
 */
class ReconciliationService
{
    public const LOCK = 'reconciliation:run';

    public const LOCK_SECONDS = 1800;

    public function __construct(
        private readonly AssetReconciler $reconciler,
        private readonly ReconciliationFingerprint $fingerprints,
        private readonly ReconciliationStore $store,
        private readonly TradingWeekResolver $calendar,
    ) {}

    /**
     * @param  array{
     *   symbols?: array<int, string>|null,
     *   force?: bool,
     *   dry_run?: bool,
     *   verify?: bool,
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->blocked();
        }

        try {
            return $this->reconcile($options);
        } finally {
            $lock->release();
        }
    }

    /**
     * The pass itself, without the lock, so a caller already holding one --
     * the scheduled task runner does -- is not deadlocked by it.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function reconcile(array $options = []): array
    {
        $startedAt = microtime(true);
        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verify = (bool) ($options['verify'] ?? false);

        $previous = $this->store->readManifest();
        $previousAssets = is_array($previous['assets'] ?? null) ? $previous['assets'] : [];

        $assets = $this->assets($options['symbols'] ?? null);
        $asOf = $this->calendar->latestTradingDayOnOrBefore($this->calendar->today(), 30);

        $entries = [];
        $checked = 0;
        $changed = [];
        $skipped = [];
        $failed = [];
        $verifyFailures = [];

        foreach (array_chunk($assets, max(1, (int) config('reconciliation.chunk_size', 25))) as $chunk) {
            $fingerprints = $this->fingerprints->forAssets($chunk);

            foreach ($chunk as $asset) {
                $symbol = strtoupper((string) $asset->symbol);
                $checked++;

                $fingerprint = $fingerprints[(int) $asset->id] ?? '';
                $stored = $previousAssets[$symbol] ?? null;

                $unchanged = ! $force
                    && is_array($stored)
                    && ($stored['source_fingerprint'] ?? null) === $fingerprint
                    && $this->documentIntact($symbol, $stored, $verify);

                if ($unchanged) {
                    $skipped[] = $symbol;
                    $entries[$symbol] = $stored;

                    continue;
                }

                try {
                    // Built one at a time: a document carries every bar and
                    // every broker entry an asset has, and holding several
                    // hundred of those at once is how a nightly job starts
                    // needing a gigabyte.
                    $document = $this->reconciler->build($asset, $fingerprint, $asOf);

                    if ($dryRun) {
                        $encoded = $this->store->encode($document);
                        $changed[] = $symbol;
                        $entries[$symbol] = $this->entry($symbol, $document, hash('sha256', $encoded), strlen($encoded));

                        continue;
                    }

                    $written = $this->store->writeAsset($symbol, $document);

                    if ($written['changed']) {
                        $changed[] = $symbol;
                    } else {
                        $skipped[] = $symbol;
                    }

                    $entries[$symbol] = $this->entry($symbol, $document, $written['hash'], $written['size']);
                } catch (Throwable $exception) {
                    // One asset failing is a reportable condition, not a
                    // reason to abandon the other four hundred. The previous
                    // manifest entry is carried forward so the index keeps
                    // describing the document that is actually on disk.
                    $failed[$symbol] = $exception->getMessage();

                    if (is_array($stored)) {
                        $entries[$symbol] = $stored;
                    }
                }
            }
        }

        if ($verify) {
            $verifyFailures = $this->verifyDocuments(array_keys($entries));
        }

        ksort($entries);

        $manifest = $this->manifest($entries, $asOf);

        $manifestWrite = $dryRun
            ? ['path' => $this->store->manifestPath(), 'hash' => hash('sha256', $this->store->encode($manifest)), 'size' => strlen($this->store->encode($manifest)), 'changed' => false]
            : $this->store->writeManifest($manifest);

        sort($changed);
        sort($skipped);

        return [
            'dry_run' => $dryRun,
            'blocked' => false,
            'assets_checked' => $checked,
            'assets_changed' => $changed,
            'assets_skipped' => $skipped,
            'assets_failed' => $failed,
            'verify_failures' => $verifyFailures,
            'summary' => $manifest['summary'],
            'manifest_path' => $manifestWrite['path'],
            'manifest_hash' => $manifestWrite['hash'],
            'manifest_changed' => $manifestWrite['changed'],
            'market_date' => $manifest['market_date'],
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * Whether the stored document still matches what the manifest claims.
     *
     * Only consulted under `--verify`, because it costs a read per asset. The
     * cheap path trusts the manifest, which is correct as long as nothing but
     * this service writes the directory; verify exists for when that
     * assumption needs testing rather than believing.
     *
     * @param  array<string, mixed>  $stored
     */
    private function documentIntact(string $symbol, array $stored, bool $verify): bool
    {
        $path = $this->store->assetPath($symbol);

        if (! $this->store->exists($path)) {
            // The manifest says it is current and the file is gone. Rebuild
            // rather than skip: the index is not the recovery data.
            return false;
        }

        if (! $verify) {
            return true;
        }

        return $this->store->hashOf($path) === ($stored['hash'] ?? null);
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, string>
     */
    private function verifyDocuments(array $symbols): array
    {
        $failures = [];
        $expected = (int) config('reconciliation.schema_version', 1);

        foreach ($symbols as $symbol) {
            try {
                $document = $this->store->readAsset($symbol);

                if ($document === null) {
                    $failures[$symbol] = 'The reconciliation document is missing.';

                    continue;
                }

                if ((int) ($document['schema_version'] ?? 0) !== $expected) {
                    $failures[$symbol] = sprintf(
                        'Schema version %s, expected %d.',
                        var_export($document['schema_version'] ?? null, true),
                        $expected,
                    );
                }
            } catch (Throwable $exception) {
                $failures[$symbol] = $exception->getMessage();
            }
        }

        return $failures;
    }

    /**
     * One manifest row: enough for the dashboard's list without opening the
     * document, and nothing more.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function entry(string $symbol, array $document, string $hash, int $size): array
    {
        $coverage = $document['coverage'];
        $insight = $document['insight'];
        $integrity = $document['integrity'];

        $row = [
            'path' => $this->store->assetPath($symbol),
            'hash' => $hash,
            'size' => $size,
            'source_fingerprint' => $document['source_fingerprint'],
            'generated_at' => $document['generated_at'],

            'ohlcv_first' => $coverage['ohlcv']['first_date'],
            'ohlcv_last' => $coverage['ohlcv']['last_date'],
            'ohlcv_rows' => $coverage['ohlcv']['rows'],
            'ohlcv_source_exists' => $coverage['ohlcv']['source_exists'],

            'broker_first' => $coverage['broker_summary']['first_window_from'],
            'broker_last' => $coverage['broker_summary']['last_window_to'],
            'latest_broker_daily' => $coverage['broker_summary']['latest_single_day'],
            'broker_daily_windows' => $coverage['broker_summary']['single_day_window_count'],
            'broker_aggregate_windows' => $coverage['broker_summary']['aggregate_window_count'],

            'integrity_status' => $integrity['status'],
            'gap_count' => $integrity['missing_broker_session_count'],
            'warning_count' => count($integrity['warnings']),
            'error_count' => count($integrity['errors']),
            'broker_lag_sessions' => $integrity['broker_lag_sessions'],

            'latest_accdist' => $insight['latest_accdist'],
            'latest_accdist_score' => $insight['latest_accdist_score'],
            'daily_sessions_total' => $insight['daily_sessions_total'],
        ];

        foreach ((array) config('reconciliation.flow_windows', [5, 20]) as $window) {
            $window = (int) $window;
            $row['flow_balance_'.$window.'d'] = $insight['flow_balance_'.$window.'d'] ?? null;
            $row['available_daily_sessions_'.$window.'d'] = $insight['available_daily_sessions_'.$window.'d'] ?? null;
            $row['price_return_'.$window.'d'] = $insight['price_return_'.$window.'d'] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function manifest(array $entries, ?Carbon $asOf): array
    {
        $healthy = 0;
        $warning = 0;
        $error = 0;
        $latestOhlcv = null;
        $latestBrokerDaily = null;
        $gapped = 0;

        foreach ($entries as $entry) {
            match ($entry['integrity_status'] ?? 'healthy') {
                'error' => $error++,
                'warning' => $warning++,
                default => $healthy++,
            };

            if (($entry['gap_count'] ?? 0) > 0) {
                $gapped++;
            }

            $ohlcvLast = $entry['ohlcv_last'] ?? null;
            $brokerLast = $entry['latest_broker_daily'] ?? null;

            if (is_string($ohlcvLast) && ($latestOhlcv === null || $ohlcvLast > $latestOhlcv)) {
                $latestOhlcv = $ohlcvLast;
            }

            if (is_string($brokerLast) && ($latestBrokerDaily === null || $brokerLast > $latestBrokerDaily)) {
                $latestBrokerDaily = $brokerLast;
            }
        }

        // "Current" is measured against the fleet's own newest date rather
        // than against today: on a trading afternoon before publication
        // nothing is current by the clock, and reporting the whole universe
        // as stale every evening would train the reader to ignore the field.
        $ohlcvCurrent = 0;
        $brokerCurrent = 0;

        foreach ($entries as $entry) {
            if ($latestOhlcv !== null && ($entry['ohlcv_last'] ?? null) === $latestOhlcv) {
                $ohlcvCurrent++;
            }

            if ($latestBrokerDaily !== null && ($entry['latest_broker_daily'] ?? null) === $latestBrokerDaily) {
                $brokerCurrent++;
            }
        }

        return [
            'schema_version' => (int) config('reconciliation.schema_version', 1),
            'generated_at' => Carbon::now($this->calendar->timezone())->toIso8601String(),
            'market_date' => $asOf?->toDateString(),
            'summary' => [
                'asset_count' => count($entries),
                'healthy' => $healthy,
                'warning' => $warning,
                'error' => $error,
                'with_gaps' => $gapped,
                'ohlcv_current' => $ohlcvCurrent,
                'broker_current' => $brokerCurrent,
                'latest_ohlcv_date' => $latestOhlcv,
                'latest_broker_daily_date' => $latestBrokerDaily,
            ],
            'assets' => $entries,
        ];
    }

    /**
     * @param  array<int, string>|null  $symbols
     * @return array<int, Asset>
     */
    private function assets(?array $symbols): array
    {
        $query = Asset::query()->orderBy('symbol');

        if ($symbols !== null && $symbols !== []) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        }

        return $query->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(): array
    {
        return [
            'dry_run' => false,
            'blocked' => true,
            'assets_checked' => 0,
            'assets_changed' => [],
            'assets_skipped' => [],
            'assets_failed' => [],
            'verify_failures' => [],
            'summary' => [],
            'manifest_path' => $this->store->manifestPath(),
            'manifest_hash' => null,
            'manifest_changed' => false,
            'market_date' => null,
            'duration_seconds' => 0.0,
        ];
    }
}
