<?php

namespace App\Console\Commands\Reconciliation;

use App\Services\Reconciliation\ReconciliationMirror;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Rebuild the canonical recovery layer for whatever has changed.
 *
 * Cheap to run often: every asset is checked, but only assets whose inputs
 * actually moved are rebuilt, and only rebuilt documents are uploaded. The
 * steady-state evening run touches a handful of files.
 */
class DataReconcileCommand extends Command
{
    protected $signature = 'data:reconcile
        {--symbol=* : Restrict to specific tickers (repeatable or comma-separated)}
        {--all : Reconcile every asset (the default when no --symbol is given)}
        {--force : Rebuild even when the fingerprint says nothing changed}
        {--dry-run : Report what would change without writing or uploading}
        {--verify : Re-read each document and check it against the manifest}
        {--mirror : Push changed documents, then the manifest, to cold storage}
        {--disk= : Mirror to this disk instead of the configured one}';

    protected $description = 'Rebuild changed asset reconciliation documents and the manifest that indexes them.';

    public function handle(ReconciliationService $service, ReconciliationMirror $mirror): int
    {
        $symbols = $this->parseSymbols((array) $this->option('symbol'));
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->run([
            'symbols' => $symbols,
            'force' => (bool) $this->option('force'),
            'dry_run' => $dryRun,
            'verify' => (bool) $this->option('verify'),
        ]);

        if ($result['blocked']) {
            $this->warn('Another reconciliation run holds the lock; nothing was done.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d asset(s): %d changed, %d unchanged, %d failed.',
            $dryRun ? 'Would reconcile' : 'Reconciled',
            $result['assets_checked'],
            count($result['assets_changed']),
            count($result['assets_skipped']),
            count($result['assets_failed']),
        ));

        if ($result['assets_changed'] !== []) {
            $this->line('  Changed: '.$this->summarise($result['assets_changed']));
        }

        $summary = $result['summary'];

        if ($summary !== []) {
            $this->line(sprintf(
                '  Health: %d healthy, %d warning, %d error; %d with gaps.',
                $summary['healthy'],
                $summary['warning'],
                $summary['error'],
                $summary['with_gaps'],
            ));
            $this->line(sprintf(
                '  Latest OHLCV %s, latest daily broker %s.',
                (string) ($summary['latest_ohlcv_date'] ?? 'n/a'),
                (string) ($summary['latest_broker_daily_date'] ?? 'n/a'),
            ));
        }

        foreach ($result['assets_failed'] as $symbol => $message) {
            $this->error(sprintf('  %s: %s', $symbol, $message));
        }

        foreach ($result['verify_failures'] as $symbol => $message) {
            $this->error(sprintf('  verify %s: %s', $symbol, $message));
        }

        if (! $dryRun) {
            $this->line(sprintf('Manifest %s (%s).', $result['manifest_changed'] ? 'updated' : 'unchanged', $result['manifest_hash']));
        }

        $exit = ($result['assets_failed'] === [] && $result['verify_failures'] === [])
            ? self::SUCCESS
            : self::FAILURE;

        if ($this->option('mirror') && ! $dryRun) {
            $exit = min($exit, $this->mirror($mirror, $result));
        }

        return $exit === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function mirror(ReconciliationMirror $mirror, array $result): int
    {
        $push = $mirror->push([], $this->option('disk') ?: null);

        if (! $push['enabled']) {
            $this->line('Mirror skipped: '.(string) ($push['manifest']['reason'] ?? 'no disk configured.'));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Mirror to %s: %d uploaded, %d already current, %d failed.',
            (string) $push['disk'],
            count($push['assets']['uploaded']),
            count($push['assets']['skipped']),
            count($push['assets']['failed']),
        ));

        foreach ($push['assets']['failed'] as $symbol => $message) {
            $this->error(sprintf('  %s: %s', $symbol, $message));
        }

        $status = (string) $push['manifest']['status'];

        if ($status === 'withheld') {
            // The commit marker deliberately did not advance. Said plainly,
            // because "some uploads failed" and "the remote index now points
            // at documents that are not there" are very different problems
            // and only one of them is this one.
            $this->warn('Manifest withheld: '.(string) $push['manifest']['reason']);

            return self::FAILURE;
        }

        if ($status === ReconciliationMirror::FAILED) {
            $this->error('Manifest upload failed: '.(string) $push['manifest']['reason']);

            return self::FAILURE;
        }

        $this->info(sprintf('Manifest %s.', $status));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function summarise(array $symbols): string
    {
        $cap = 20;

        if (count($symbols) <= $cap) {
            return implode(', ', $symbols);
        }

        return implode(', ', array_slice($symbols, 0, $cap)).sprintf(' … and %d more', count($symbols) - $cap);
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
