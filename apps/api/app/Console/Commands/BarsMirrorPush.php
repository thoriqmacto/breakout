<?php

namespace App\Console\Commands;

use App\Services\BarCsvMirror;
use Illuminate\Console\Command;

/**
 * One-time (and repeatable) push of the local seed CSVs to the mirror disk.
 *
 * This is the migration path onto Drive: it walks csv.seed_dir and uploads
 * every CSV, then reports local vs remote counts so the two can be compared
 * before anyone considers the local copy expendable.
 */
class BarsMirrorPush extends Command
{
    protected $signature = 'bars:mirror-push
        {--disk=gdrive : Mirror disk to upload to}
        {--symbol=* : Limit the push to specific symbols}
        {--force : Re-upload every CSV, ignoring the mirrored-hash manifest}';

    protected $description = 'Upload local OHLCV seed CSVs to the mirror disk (Google Drive by default).';

    public function handle(BarCsvMirror $mirror): int
    {
        $disk = $mirror->resolveDisk((string) $this->option('disk'));

        if ($disk === null) {
            $this->error('No mirror disk resolved. Pass --disk=gdrive or set CSV_MIRROR_DISK.');

            return self::INVALID;
        }

        /** @var array<int, string> $symbols */
        $symbols = $this->option('symbol') ?: [];

        if ($this->option('force')) {
            $mirror->forget($symbols);
        }

        $local = $mirror->localSymbols();

        if ($local === []) {
            $this->warn('No seed CSVs found in '.config('csv.seed_dir').'; nothing to push.');

            return self::SUCCESS;
        }

        $targets = $symbols !== [] ? $symbols : $local;

        $this->info(sprintf('Pushing %d seed CSV(s) to [%s]…', count($targets), $disk));

        $result = $mirror->flush($symbols, $disk);

        $this->line(sprintf(
            'Uploaded %d, skipped %d (already mirrored or missing locally), failed %d.',
            count($result['uploaded']),
            count($result['skipped']),
            count($result['failed'])
        ));

        if ($result['failed'] !== []) {
            $this->error('Failed: '.implode(', ', $result['failed']));
        }

        $remote = $mirror->remoteSymbols(null, $disk);

        $this->table(
            ['Location', 'CSV count'],
            [
                ['local ('.config('csv.seed_dir').')', (string) count($local)],
                [$disk.' ('.config('csv.mirror_path').')', (string) count($remote)],
            ]
        );

        $missing = array_values(array_diff($local, $remote));

        if ($missing !== []) {
            $this->warn(sprintf(
                '%d local CSV(s) are not on the mirror yet: %s',
                count($missing),
                implode(', ', $missing)
            ));

            return self::FAILURE;
        }

        $this->info('Every local seed CSV is present on the mirror.');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
