<?php

namespace App\Console\Commands\Concerns;

use App\Services\Automation\RunMetadata;
use App\Services\BarCsvMirror;

/**
 * Adds the seed-CSV mirror bookends to a data-mutating command.
 *
 * The command keeps doing its CSV work on local disk exactly as before; this
 * only hydrates the local copies beforehand and pushes the changed ones after.
 * Both halves are no-ops unless a mirror disk is configured or passed via
 * --disk, so local-only development is unaffected.
 *
 * Commands using this trait must declare a `{--disk= : ...}` option.
 */
trait MirrorsSeedCsvs
{
    private ?BarCsvMirror $barCsvMirror = null;

    protected function barCsvMirror(): BarCsvMirror
    {
        return $this->barCsvMirror ??= app(BarCsvMirror::class);
    }

    /**
     * The mirror disk for this run, or null when mirroring is disabled.
     */
    protected function mirrorDisk(): ?string
    {
        $option = $this->hasOption('disk') ? $this->option('disk') : null;

        return $this->barCsvMirror()->resolveDisk(is_string($option) ? $option : null);
    }

    /**
     * Pull seed CSVs from the mirror before the local read/merge/write loop.
     *
     * @param  array<int, string>  $symbols  Empty pulls every CSV on the mirror.
     */
    protected function hydrateSeedCsvs(array $symbols = []): void
    {
        $disk = $this->mirrorDisk();

        if ($disk === null) {
            return;
        }

        $result = $this->barCsvMirror()->hydrate($symbols, $disk);

        if ($result['hydrated'] !== []) {
            $this->line(sprintf(
                'Hydrated %d seed CSV(s) from [%s]: %s',
                count($result['hydrated']),
                $disk,
                implode(', ', $result['hydrated'])
            ));
        }

        if ($result['failed'] !== []) {
            $this->warn(sprintf(
                'Failed to hydrate %d seed CSV(s) from [%s]: %s',
                count($result['failed']),
                $disk,
                implode(', ', $result['failed'])
            ));
        }
    }

    /**
     * Push changed seed CSVs to the mirror after the local loop and the DB flush.
     *
     * @param  array<int, string>  $symbols  Empty pushes every local CSV.
     */
    protected function flushSeedCsvs(array $symbols = []): void
    {
        $disk = $this->mirrorDisk();

        if ($disk === null) {
            return;
        }

        $result = $this->barCsvMirror()->flush($symbols, $disk);

        // This trait is the one place seed CSVs are pushed to the mirror, so
        // it is also the only honest place to report what that push did. A
        // scheduled run needs the numbers as data, not as a line of console
        // prose, and duplicating the flush in the automation layer purely to
        // observe it would upload everything a second time.
        $this->recordMirrorOutcome($result);

        if ($result['uploaded'] !== []) {
            $this->line(sprintf(
                'Mirrored %d seed CSV(s) to [%s]: %s',
                count($result['uploaded']),
                $disk,
                implode(', ', $result['uploaded'])
            ));
        }

        if ($result['failed'] !== []) {
            $this->warn(sprintf(
                'Failed to mirror %d seed CSV(s) to [%s]: %s',
                count($result['failed']),
                $disk,
                implode(', ', $result['failed'])
            ));
        }
    }

    /**
     * Hand the flush result to the scheduler's per-run metadata collector.
     *
     * Outside a scheduled run nothing reads it and this is a no-op write into
     * a throwaway object, so a command invoked from a terminal is unaffected.
     *
     * @param  array{disk: ?string, uploaded: array<int, string>, skipped: array<int, string>, failed: array<int, string>}  $result
     */
    private function recordMirrorOutcome(array $result): void
    {
        app(RunMetadata::class)->set('gdrive', [
            'disk' => $result['disk'],
            'uploaded' => $result['uploaded'],
            'skipped_unchanged' => count($result['skipped']),
            'failed' => $result['failed'],
            'status' => $result['failed'] === [] ? 'ok' : 'failed',
        ]);
    }
}
