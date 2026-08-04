<?php

namespace App\Console\Commands;

use App\Services\BarCsvMirror;
use Illuminate\Console\Command;

/**
 * Disaster recovery: restore the local seed CSVs from the mirror disk.
 *
 * The inverse of bars:mirror-push. By default it only fills in CSVs that are
 * missing or older locally; --force overwrites every local copy with the
 * remote one.
 */
class BarsMirrorPull extends Command
{
    protected $signature = 'bars:mirror-pull
        {--disk=gdrive : Mirror disk to download from}
        {--symbol=* : Limit the pull to specific symbols}
        {--force : Overwrite local CSVs even when they are newer than the mirror}';

    protected $description = 'Restore local OHLCV seed CSVs from the mirror disk (Google Drive by default).';

    public function handle(BarCsvMirror $mirror): int
    {
        $disk = $mirror->resolveDisk((string) $this->option('disk'));

        if ($disk === null) {
            $this->error('No mirror disk resolved. Pass --disk=gdrive or set CSV_MIRROR_DISK.');

            return self::INVALID;
        }

        /** @var array<int, string> $symbols */
        $symbols = $this->option('symbol') ?: [];

        $remote = $mirror->remoteSymbols(null, $disk);

        if ($remote === []) {
            $this->warn(sprintf('No CSVs found on [%s] under %s; nothing to pull.', $disk, config('csv.mirror_path')));

            return self::SUCCESS;
        }

        $targets = $symbols !== [] ? $symbols : $remote;

        $this->info(sprintf('Pulling %d seed CSV(s) from [%s]…', count($targets), $disk));

        $result = $mirror->hydrate($symbols, $disk, (bool) $this->option('force'));

        $this->line(sprintf(
            'Downloaded %d, skipped %d (local copy already current), failed %d.',
            count($result['hydrated']),
            count($result['skipped']),
            count($result['failed'])
        ));

        if ($result['failed'] !== []) {
            $this->error('Failed: '.implode(', ', $result['failed']));
        }

        $local = $mirror->localSymbols();

        $this->table(
            ['Location', 'CSV count'],
            [
                [$disk.' ('.config('csv.mirror_path').')', (string) count($remote)],
                ['local ('.config('csv.seed_dir').')', (string) count($local)],
            ]
        );

        $missing = array_values(array_diff($remote, $local));

        if ($missing !== []) {
            $this->warn(sprintf(
                '%d mirrored CSV(s) are still missing locally: %s',
                count($missing),
                implode(', ', $missing)
            ));

            return self::FAILURE;
        }

        $this->info('Every mirrored seed CSV is present locally.');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
