<?php

namespace App\Services\Reconciliation;

use App\Services\BrokerSummaryArchiveMirror;
use App\Services\GoogleDriveHealth;
use Illuminate\Support\Carbon;

/**
 * The fast answer to "is my recoverable dataset current?".
 *
 * Everything here comes from three reads: Drive health, the local manifest,
 * and the remote manifest's hash. That is the whole point. The deep audit --
 * comparing every raw file's content against its Drive copy -- is genuinely
 * valuable and genuinely expensive, and its cost grows with every
 * broker-summary JSON ever written, so making the ordinary page load pay it
 * meant the page got slower every single day it was used.
 *
 * So the two questions are separated by what they cost rather than bundled by
 * what they are about:
 *
 *   readiness   can I recover, and is the recovery copy current?   (seconds)
 *   deep audit  do the individual raw files match byte for byte?   (minutes)
 *
 * Nothing here reports "synchronised" from the presence of a filename. The
 * remote manifest is compared by hash or the state is reported as unknown.
 */
class ReconciliationReadiness
{
    public function __construct(
        private readonly ReconciliationStore $store,
        private readonly ReconciliationMirror $mirror,
        private readonly GoogleDriveHealth $health,
        private readonly BrokerSummaryArchiveMirror $archive,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(string $remoteDisk = 'gdrive'): array
    {
        $manifest = $this->store->readManifest();
        $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];

        $remote = $this->mirror->remoteState();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'google_drive' => $this->health->check($remoteDisk),
            'reconciliation' => [
                'present' => $manifest !== [],
                'schema_version' => $manifest['schema_version'] ?? null,
                'generated_at' => $manifest['generated_at'] ?? null,
                'market_date' => $manifest['market_date'] ?? null,
                'manifest_path' => $this->store->manifestPath(),
                'manifest_hash' => $remote['local_manifest_hash'],
                'asset_count' => $summary['asset_count'] ?? 0,
                'healthy' => $summary['healthy'] ?? 0,
                'warning' => $summary['warning'] ?? 0,
                'error' => $summary['error'] ?? 0,
                'with_gaps' => $summary['with_gaps'] ?? 0,
                'ohlcv_current' => $summary['ohlcv_current'] ?? 0,
                'broker_current' => $summary['broker_current'] ?? 0,
                'latest_ohlcv_date' => $summary['latest_ohlcv_date'] ?? null,
                'latest_broker_daily_date' => $summary['latest_broker_daily_date'] ?? null,
            ],
            'mirror' => $remote,
            // The raw archive is a separate layer with a separate mirror, and
            // the page should not offer a repair that can only fail.
            // Resolved from configuration, not from the disk name this report
            // was asked about: naming a disk here would report the archive as
            // mirrored on a server where archive mirroring is switched off.
            'raw_archive' => [
                'mirror_enabled' => $this->archive->enabled(),
                'disk' => $this->archive->resolveDisk(),
                'path' => trim((string) config('stockbit.save_dir', 'broker_summary'), '/'),
            ],
            'readiness' => $this->readiness($manifest, $summary, $remote),
            'flow_snapshot' => $this->flowSnapshot($assets),
        ];
    }

    /**
     * One overall verdict, with the conditions that produced it.
     *
     * Deliberately conservative. "Ready" means a manifest exists, describes
     * assets, has no errors, and matches what cold storage holds -- all four,
     * because any one of them failing is a recovery that would not complete.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function readiness(array $manifest, array $summary, array $remote): array
    {
        $blockers = [];
        $warnings = [];

        $localMissing = $manifest === [];

        if ($localMissing) {
            $blockers[] = 'No reconciliation manifest exists on this server. Run "php artisan data:reconcile --all".';
        } elseif (($summary['asset_count'] ?? 0) === 0) {
            $blockers[] = 'The reconciliation manifest describes no assets.';
        }

        if (($summary['error'] ?? 0) > 0) {
            $blockers[] = sprintf('%d asset(s) have reconciliation errors.', (int) $summary['error']);
        }

        if (($summary['warning'] ?? 0) > 0) {
            $warnings[] = sprintf('%d asset(s) have reconciliation warnings.', (int) $summary['warning']);
        }

        if (! $remote['enabled']) {
            $warnings[] = 'No cold-storage mirror is configured, so the recovery layer exists only on this server.';
        } elseif (! $remote['reachable']) {
            // Distinguished from "the folder is empty" on purpose: an OAuth
            // failure and an unpublished manifest need completely different
            // responses, and reporting either as the other wastes the reader's
            // time at the moment they can least afford it.
            $blockers[] = 'Cold storage could not be reached, so the off-server copy cannot be confirmed.';
        } elseif (! $remote['manifest_present']) {
            $blockers[] = 'No reconciliation manifest has been published to cold storage yet.';
        } elseif ($localMissing) {
            // Not "cold storage is behind" -- the opposite. Saying that here
            // points at a push, and pushing would replace the one surviving
            // copy with nothing. This state is recoverable, and the blocker
            // should say which direction recovers it.
            $blockers[] = 'Cold storage holds a reconciliation manifest that this server does not. '
                .'Rebuild it with "php artisan data:reconcile --all", or recover from the published copy '
                .'with "php artisan data:restore --all --disk='.($remote['disk'] ?? 'gdrive').'". '
                .'Do not push: that would overwrite the published copy with nothing.';
        } elseif (! $remote['in_sync']) {
            $blockers[] = 'The published manifest differs from the local one, so cold storage is behind.';
        }

        return [
            'status' => $blockers !== [] ? 'not_ready' : ($warnings !== [] ? 'degraded' : 'ready'),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * The broker-flow overview, straight from the manifest.
     *
     * Descriptive: who has been accumulating, who has been distributing, and
     * -- given equal weight rather than hidden -- who does not have enough
     * daily observations for either statement to mean anything. A ranking
     * that quietly includes symbols with two sessions of data is worse than
     * no ranking.
     *
     * @param  array<string, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function flowSnapshot(array $assets): array
    {
        $window = (int) (config('reconciliation.flow_windows', [5, 20])[0] ?? 5);
        $balanceKey = 'flow_balance_'.$window.'d';
        $availableKey = 'available_daily_sessions_'.$window.'d';

        $ranked = [];
        $insufficient = [];

        foreach ($assets as $symbol => $entry) {
            $available = (int) ($entry[$availableKey] ?? 0);

            $row = [
                'symbol' => $symbol,
                'latest_broker_date' => $entry['latest_broker_daily'] ?? null,
                'latest_accdist' => $entry['latest_accdist'] ?? null,
                'flow_balance' => $entry[$balanceKey] ?? null,
                'available_sessions' => $available,
                'required_sessions' => $window,
                'price_return' => $entry['price_return_'.$window.'d'] ?? null,
                'daily_windows' => $entry['broker_daily_windows'] ?? 0,
                'integrity_status' => $entry['integrity_status'] ?? 'healthy',
            ];

            // A full window of observations or it does not get ranked. The
            // dashboard shows the rest as insufficient rather than as neutral.
            if ($available < $window) {
                $insufficient[] = $row;

                continue;
            }

            $ranked[] = $row;
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['flow_balance'], $a['symbol']] <=> [$a['flow_balance'], $b['symbol']]);
        usort($insufficient, static fn (array $a, array $b): int => [$b['available_sessions'], $a['symbol']] <=> [$a['available_sessions'], $b['symbol']]);

        $accumulating = array_values(array_filter($ranked, static fn (array $row): bool => (int) $row['flow_balance'] > 0));
        $distributing = array_values(array_filter($ranked, static fn (array $row): bool => (int) $row['flow_balance'] < 0));

        return [
            'window' => $window,
            'ranked_count' => count($ranked),
            'accumulating' => array_slice($accumulating, 0, 10),
            'distributing' => array_slice(array_reverse($distributing), 0, 10),
            'insufficient' => array_slice($insufficient, 0, 10),
            'insufficient_count' => count($insufficient),
            'note' => sprintf(
                'Flow balance sums the accumulation/distribution label over the last %d genuine single-day broker sessions. Symbols with fewer than %d are listed separately rather than shown as neutral.',
                $window,
                $window,
            ),
        ];
    }
}
