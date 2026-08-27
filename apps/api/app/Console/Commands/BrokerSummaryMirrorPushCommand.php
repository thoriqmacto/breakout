<?php

namespace App\Console\Commands;

use App\Services\BrokerSummaryArchiveMirror;
use Illuminate\Console\Command;

/**
 * Push the broker-summary JSON archive to cold storage.
 *
 * The counterpart to `bars:mirror-push` for the other half of what this system
 * produces. Repeatable and safe: files whose remote copy already holds the
 * same bytes are skipped, and a failed upload never touches the local file.
 */
class BrokerSummaryMirrorPushCommand extends Command
{
    protected $signature = 'broker-summary:mirror-push
        {--disk= : Mirror disk to upload to (default: BROKER_SUMMARY_MIRROR_DISK)}
        {--since= : Only files modified on or after this YYYY-MM-DD date}
        {--force : Ignored; content is always compared before uploading}';

    protected $description = 'Upload the local broker-summary JSON archive to the cold-storage disk.';

    public function handle(BrokerSummaryArchiveMirror $mirror): int
    {
        $disk = $mirror->resolveDisk((string) ($this->option('disk') ?: ''));

        if ($disk === null) {
            $this->error('No mirror disk resolved. Pass --disk=gdrive or set BROKER_SUMMARY_MIRROR_DISK.');

            return self::INVALID;
        }

        $since = $this->option('since');
        $since = is_string($since) && $since !== '' ? $since : null;

        $paths = $mirror->localPaths($since);

        if ($paths === []) {
            $this->warn('No broker-summary JSON found locally; nothing to push.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Comparing %d archive file(s) against [%s]…', count($paths), $disk));

        $result = $mirror->mirror($paths, $disk);

        $this->line(sprintf(
            'Uploaded %d, already up to date %d, missing locally %d, failed %d.',
            count($result['uploaded']),
            count($result['skipped_unchanged']),
            count($result['missing']),
            count($result['failed']),
        ));

        foreach ($result['failed'] as $failure) {
            $this->error($failure['path'].': '.$failure['message']);
        }

        if ($result['failed'] !== []) {
            $this->warn('The local copies of the failed files are untouched and remain the source of truth.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
