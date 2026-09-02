<?php

namespace App\Console\Commands\Reconciliation;

use App\Services\Reconciliation\ReconciliationRestorer;
use Illuminate\Console\Command;

/**
 * Rebuild canonical state from the reconciliation layer.
 *
 * The fast disaster-recovery path: code, migrations and the reconciliation
 * directory are enough to restore OHLCV and broker-summary state without
 * parsing the raw archive. `broker-summary:rebuild` remains the forensic
 * alternative when the original responses are what matters.
 *
 * Defaults to a dry run's caution even when writing: every document is
 * validated before anything is touched, and an asset that fails validation is
 * skipped and named rather than partially applied.
 */
class DataRestoreCommand extends Command
{
    protected $signature = 'data:restore
        {--symbol=* : Restrict to specific tickers (repeatable or comma-separated)}
        {--all : Restore every asset in the manifest (the default when no --symbol is given)}
        {--disk= : Read the reconciliation layer from this disk instead of locally}
        {--dry-run : Report what would be restored without writing}
        {--skip-csv : Rebuild the database only, leaving the seed CSVs alone}';

    protected $description = 'Restore OHLCV and broker-summary state from the reconciliation layer.';

    public function handle(ReconciliationRestorer $restorer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $restorer->restore([
            'symbols' => $this->parseSymbols((array) $this->option('symbol')),
            'disk' => $this->option('disk') ?: null,
            'dry_run' => $dryRun,
            'skip_csv' => (bool) $this->option('skip-csv'),
        ]);

        if (isset($result['error'])) {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Reading from %s, manifest generated %s (schema %s).',
            (string) $result['source'],
            (string) ($result['manifest_generated_at'] ?? 'unknown'),
            (string) ($result['manifest_schema_version'] ?? '?'),
        ));

        $this->line(sprintf(
            '%s %d of %d asset(s).',
            $dryRun ? 'Would restore' : 'Restored',
            count($result['restored']),
            $result['requested'],
        ));

        $bars = 0;
        $windows = 0;
        $entries = 0;
        $aggregates = 0;

        foreach ($result['assets'] as $detail) {
            $bars += (int) ($detail['ohlcv_rows'] ?? 0);
            $windows += (int) ($detail['windows'] ?? 0);
            $entries += (int) ($detail['entries'] ?? 0);
            $aggregates += (int) ($detail['aggregate_windows'] ?? 0);
        }

        $this->line(sprintf(
            '  %d OHLCV row(s), %d broker window(s) (%d range aggregate(s) kept as aggregates), %d entry/entries.',
            $bars,
            $windows,
            $aggregates,
            $entries,
        ));

        if ($result['missing_from_manifest'] !== []) {
            $this->warn('Not in the manifest: '.implode(', ', $result['missing_from_manifest']));
        }

        foreach ($result['failed'] as $symbol => $message) {
            $this->error(sprintf('  %s: %s', $symbol, $message));
        }

        if ($result['failed'] !== []) {
            $this->error(sprintf('%d asset(s) failed and were left untouched.', count($result['failed'])));

            return self::FAILURE;
        }

        return self::SUCCESS;
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
