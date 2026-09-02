<?php

namespace App\Console\Commands\Automation;

use App\Services\Automation\RunMetadata;
use App\Services\Reconciliation\ReconciliationMirror;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

/**
 * The nightly reconciliation pass.
 *
 * Sits between broker-summary collection and the analysis refresh, and the
 * position is deliberate rather than incidental: it reads what the collectors
 * just wrote, so racing them would reconcile a half-collected evening and
 * publish it as the recovery copy. Priority ordering in the scheduler keeps
 * the sequence
 *
 *     trading calendar -> OHLCV -> broker summary -> reconciliation -> analysis
 *
 * Every asset is examined; only assets whose inputs moved are rebuilt, and
 * only rebuilt documents are uploaded. In the steady state that is a handful
 * of files out of several hundred, which is what makes running it every night
 * reasonable at all.
 */
class DataReconciliationCommand extends Command
{
    protected $signature = 'automation:data-reconciliation
        {--symbol=* : Restrict to specific tickers}
        {--force : Rebuild every asset regardless of its fingerprint}
        {--verify : Re-read each document and check it against the manifest}
        {--no-mirror : Skip the cold-storage mirror for this run}
        {--disk= : Mirror to this disk instead of the configured one}';

    protected $description = 'Rebuild changed reconciliation documents, refresh the manifest, and mirror them to cold storage.';

    /**
     * Failures listed by name on the run record; the counts always tell the
     * whole story.
     */
    private const MAX_REPORTED = 50;

    public function handle(
        ReconciliationService $service,
        ReconciliationMirror $mirror,
        RunMetadata $metadata,
    ): int {
        $startedAt = microtime(true);

        $metadata->set('job', 'data_reconciliation');

        $result = $service->run([
            'symbols' => $this->parseSymbols((array) $this->option('symbol')),
            'force' => (bool) $this->option('force'),
            'verify' => (bool) $this->option('verify'),
        ]);

        if ($result['blocked']) {
            // Another reconciliation holds the lock -- a manual run, or a
            // previous scheduled one still going. Skipping is correct: two
            // passes writing the same documents would race on the manifest.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'locked',
                'error_summary' => 'Another reconciliation run holds the lock.',
            ]);

            $this->warn('Another reconciliation run holds the lock; nothing was done.');

            return self::SUCCESS;
        }

        $summary = $result['summary'];

        $metadata->merge([
            'assets_checked' => $result['assets_checked'],
            'assets_changed' => count($result['assets_changed']),
            'assets_skipped_unchanged' => count($result['assets_skipped']),
            'assets_failed' => count($result['assets_failed']),
            'failed_symbols' => array_slice(array_keys($result['assets_failed']), 0, self::MAX_REPORTED),
            'changed_symbols' => array_slice($result['assets_changed'], 0, self::MAX_REPORTED),
            'assets_healthy' => $summary['healthy'] ?? 0,
            'assets_warning' => $summary['warning'] ?? 0,
            'assets_error' => $summary['error'] ?? 0,
            'assets_with_gaps' => $summary['with_gaps'] ?? 0,
            'latest_ohlcv_date' => $summary['latest_ohlcv_date'] ?? null,
            'latest_broker_daily_date' => $summary['latest_broker_daily_date'] ?? null,
            'manifest_hash' => $result['manifest_hash'],
            'manifest_changed' => $result['manifest_changed'],
            'market_date' => $result['market_date'],
        ]);

        $this->info(sprintf(
            'Checked %d asset(s): %d rebuilt, %d unchanged, %d failed.',
            $result['assets_checked'],
            count($result['assets_changed']),
            count($result['assets_skipped']),
            count($result['assets_failed']),
        ));

        foreach ($result['assets_failed'] as $symbol => $message) {
            $this->error(sprintf('  %s: %s', $symbol, $message));
        }

        $exit = self::SUCCESS;

        if ($result['assets_failed'] !== [] || $result['verify_failures'] !== []) {
            $metadata->merge([
                'partial' => true,
                'error_summary' => sprintf(
                    '%d asset(s) could not be reconciled.',
                    count($result['assets_failed']) + count($result['verify_failures']),
                ),
            ]);

            $exit = self::FAILURE;
        }

        if (! $this->option('no-mirror')) {
            $exit = $this->mirror($mirror, $metadata, $exit);
        } else {
            $metadata->set('mirror', ['status' => 'skipped']);
        }

        $metadata->merge([
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            // The reconciliation mirror above is this job's own; the runner
            // has nothing left to push.
            'mirror_handled' => true,
        ]);

        return $exit;
    }

    private function mirror(ReconciliationMirror $mirror, RunMetadata $metadata, int $exit): int
    {
        $push = $mirror->push([], $this->option('disk') ?: null);

        if (! $push['enabled']) {
            $metadata->set('mirror', ['status' => 'disabled', 'reason' => $push['manifest']['reason'] ?? null]);
            $this->line('Reconciliation mirror is not configured; documents remain local only.');

            return $exit;
        }

        $metadata->set('mirror', [
            'status' => $push['manifest']['status'],
            'disk' => $push['disk'],
            'files_mirrored' => count($push['assets']['uploaded']),
            'files_unchanged' => count($push['assets']['skipped']),
            'mirror_failures' => count($push['assets']['failed']),
            'failed_symbols' => array_slice(array_keys($push['assets']['failed']), 0, self::MAX_REPORTED),
            'degraded' => $push['degraded'],
            'reason' => $push['manifest']['reason'] ?? null,
        ]);

        $this->line(sprintf(
            'Mirror: %d uploaded, %d already current, %d failed; manifest %s.',
            count($push['assets']['uploaded']),
            count($push['assets']['skipped']),
            count($push['assets']['failed']),
            (string) $push['manifest']['status'],
        ));

        if ($push['degraded']) {
            $metadata->merge([
                'partial' => true,
                'error_summary' => trim(
                    (string) $metadata->get('error_summary', '').' '.
                    (string) ($push['manifest']['reason'] ?? 'The reconciliation mirror is degraded.')
                ),
            ]);

            $this->warn((string) ($push['manifest']['reason'] ?? 'The reconciliation mirror is degraded.'));

            return self::FAILURE;
        }

        return $exit;
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<int, string>|null
     */
    private function parseSymbols(array $raw): ?array
    {
        $out = [];

        foreach ($raw as $item) {
            foreach (explode(',', (string) $item) as $symbol) {
                $symbol = strtoupper(trim($symbol));

                if ($symbol !== '' && ! in_array($symbol, $out, true)) {
                    $out[] = $symbol;
                }
            }
        }

        return $out === [] ? null : $out;
    }
}
