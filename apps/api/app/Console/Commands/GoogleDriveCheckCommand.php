<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies a Drive-backed disk end to end from the CLI.
 *
 * GoogleDriveDiskTest covers the same ground, but `php artisan test` needs
 * phpunit and nunomaduro/collision, which are require-dev and therefore absent
 * wherever `composer install --no-dev` has run. The credentials live on that
 * host, so the check has to be a first-class command rather than a test.
 */
class GoogleDriveCheckCommand extends Command
{
    protected $signature = 'gdrive:check
        {--disk=gdrive : Disk to verify}
        {--keep : Leave the probe file behind instead of deleting it}';

    protected $description = 'Verify that a Google Drive disk can be written, read back and deleted.';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');

        if (! $this->reportConfiguration($diskName)) {
            return self::FAILURE;
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            // The gdrive driver validates its own config on resolve, so a
            // missing key file or folder id surfaces here with a usable message.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = 'smoke/gdrive-check-'.bin2hex(random_bytes(6)).'.txt';
        $first = 'breakout gdrive check '.now()->toIso8601String();
        $second = $first.' (overwritten)';

        try {
            return $this->probe($disk, $diskName, $path, $first, $second);
        } catch (Throwable $e) {
            $this->error("Unexpected failure: {$e->getMessage()}");
            $this->hint($diskName);

            return self::FAILURE;
        }
    }

    private function probe(
        Filesystem $disk,
        string $diskName,
        string $path,
        string $first,
        string $second,
    ): int {
        // The disk is configured with throw => false, so a failed API call
        // returns false rather than raising. Every result is checked -- and on
        // failure the write is repeated against a throwing copy of the disk,
        // because "false" on its own says nothing about what Google refused.
        if ($disk->put($path, $first) === false) {
            $this->error("Write failed: could not put [{$path}].");
            $this->hint($diskName, $this->explainFailure($diskName, $path, $first));

            return self::FAILURE;
        }

        $this->info('  write ......... ok');

        if (! $disk->exists($path)) {
            $this->error('Read-back failed: the file was written but does not exist.');
            $this->hint($diskName);

            return self::FAILURE;
        }

        $this->info('  exists ........ ok');

        if ($disk->get($path) !== $first) {
            $this->error('Read-back failed: the contents did not match what was written.');

            return $this->cleanup($disk, $path, self::FAILURE);
        }

        $this->info('  read .......... ok');

        // Drive allows two files with the same name in one folder, unlike a
        // POSIX filesystem. Storage::fake cannot reproduce that, so overwrite
        // semantics are only ever really tested here: a second put must replace
        // the file rather than create a sibling with the same path.
        if ($disk->put($path, $second) === false) {
            $this->error('Overwrite failed: the second write did not succeed.');

            return $this->cleanup($disk, $path, self::FAILURE);
        }

        if ($disk->get($path) !== $second) {
            $this->error('Overwrite failed: reading back returned the original contents.');

            return $this->cleanup($disk, $path, self::FAILURE);
        }

        $duplicates = collect($disk->files('smoke'))->filter(
            static fn ($candidate): bool => $candidate === $path
        )->count();

        if ($duplicates > 1) {
            $this->error(
                "Overwrite created a duplicate: {$duplicates} files share the path [{$path}]. ".
                'Mirror pushes will accumulate copies rather than replace them.'
            );

            return $this->cleanup($disk, $path, self::FAILURE);
        }

        $this->info('  overwrite ..... ok (no duplicate created)');

        if ($this->option('keep')) {
            $this->newLine();
            $this->info("Left the probe file at [{$path}] as requested.");

            return self::SUCCESS;
        }

        $disk->delete($path);

        if ($disk->exists($path)) {
            $this->error("Delete failed: [{$path}] is still present. Remove it by hand.");

            return self::FAILURE;
        }

        $this->info('  delete ........ ok');
        $this->newLine();
        $this->info("Disk [{$diskName}] is working.");

        return self::SUCCESS;
    }

    /**
     * Print what the disk resolved to, without echoing anything sensitive --
     * the key file's contents are credentials, so only its path and presence
     * are reported.
     */
    private function reportConfiguration(string $diskName): bool
    {
        $config = config("filesystems.disks.{$diskName}");

        if (! is_array($config)) {
            $this->error("There is no disk named [{$diskName}] in config/filesystems.php.");

            return false;
        }

        $this->line("Disk:   {$diskName} (driver: ".($config['driver'] ?? 'none').')');

        if (($config['driver'] ?? '') !== 'gdrive') {
            $this->warn('  This is not a gdrive disk; the check will still run against it.');

            return true;
        }

        $keyFile = (string) ($config['keyFile'] ?? '');
        $resolved = $keyFile !== '' && ! str_starts_with($keyFile, DIRECTORY_SEPARATOR)
            ? base_path($keyFile)
            : $keyFile;

        $this->line('  key file:  '.($keyFile === '' ? '(unset)' : $keyFile));

        if ($keyFile !== '') {
            $this->line('  resolved:  '.$resolved.(is_file($resolved) ? ' (found)' : ' (MISSING)'));
        }

        $teamDriveId = (string) ($config['teamDriveId'] ?? '');

        $this->line('  folder id: '.(((string) ($config['folderId'] ?? '')) === '' ? '(unset)' : 'set'));
        $this->line('  shared drive: '.($teamDriveId === '' ? '(unset)' : 'set — takes precedence over folder id'));
        $this->line('  root:      '.((string) ($config['root'] ?? 'breakout-data')));

        if ($teamDriveId === '') {
            $this->warn('  Writing into a My Drive folder. A service account has no storage quota, so it');
            $this->warn('  can create folders there but not files. Set GOOGLE_DRIVE_TEAM_DRIVE_ID instead.');
        }

        // The account identity is the one thing you need in order to share the
        // folder, and it is not a credential -- only private_key is. Printing
        // it saves opening the JSON by hand on the server.
        $identity = $this->serviceAccountIdentity($resolved);

        foreach ($identity as $label => $value) {
            $this->line(sprintf('  %-10s %s', $label.':', $value));
        }

        $this->newLine();

        return true;
    }

    /**
     * Read the non-secret identity fields out of the service-account JSON.
     *
     * @return array<string, string>
     */
    private function serviceAccountIdentity(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['key json' => 'unreadable (not valid JSON)'];
        }

        $identity = [];

        if (is_string($decoded['client_email'] ?? null)) {
            $identity['account'] = $decoded['client_email'];
        }

        if (is_string($decoded['project_id'] ?? null)) {
            $identity['project'] = $decoded['project_id'];
        }

        return $identity;
    }

    private function cleanup(
        Filesystem $disk,
        string $path,
        int $exit,
    ): int {
        $disk->delete($path);

        return $exit;
    }

    /**
     * Repeat the failed write against a copy of the disk with throw => true, so
     * the underlying Google error is printed instead of a bare false.
     *
     * Flysystem wraps the API error, so the whole `previous` chain is walked --
     * the useful part (403 insufficient permissions, 404 folder not found,
     * "Drive API has not been used in project…") is usually the innermost one.
     */
    private function explainFailure(string $diskName, string $path, string $contents): string
    {
        $config = config("filesystems.disks.{$diskName}");

        if (! is_array($config)) {
            return '';
        }

        $config['throw'] = true;

        $this->newLine();
        $this->line('Retrying with exceptions enabled to surface the underlying error:');

        try {
            Storage::build($config)->put($path, $contents);

            // Succeeded on the retry, which points at something transient
            // rather than a permission or configuration problem.
            $this->warn('  The retry succeeded. The first write may have hit a transient error or a rate limit.');
        } catch (Throwable $e) {
            $depth = 0;
            $collected = [];

            for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                $message = trim($error->getMessage());
                $collected[] = class_basename($error).' '.$message;

                $this->line(sprintf(
                    '  %s%s: %s',
                    str_repeat('  ', $depth++),
                    class_basename($error),
                    $message,
                ));
            }

            return implode(' ', $collected);
        }

        return '';
    }

    /**
     * Guidance chosen from what Google actually said, rather than a single
     * guess. An earlier version always blamed the folder share, which is
     * actively misleading when the share is correct and the real problem is
     * that a service account cannot own files at all.
     *
     * @param  string  $error  The collected error text, empty when unknown.
     */
    private function hint(string $diskName, string $error = ''): void
    {
        if ($diskName !== 'gdrive') {
            return;
        }

        $error = mb_strtolower($error);

        $this->newLine();

        if (str_contains($error, 'storagequotaexceeded') || str_contains($error, 'quota')) {
            $this->line('Google removed storage quota from service accounts, so one cannot own a file in');
            $this->line('My Drive even when the folder is shared with it. Folders cost no quota, which is');
            $this->line('why breakout-data and smoke were created but the file write then failed.');
            $this->newLine();
            $this->line('Use a Shared Drive instead: create one, add the service account as a member with');
            $this->line('Content manager, and set GOOGLE_DRIVE_TEAM_DRIVE_ID to the Shared Drive id (the');
            $this->line('part after /drive/folders/ in its URL). A Shared Drive owns its files, so no');
            $this->line('quota is charged to the account. It takes precedence over GOOGLE_DRIVE_FOLDER_ID.');

            return;
        }

        if (str_contains($error, 'notfound') || str_contains($error, '404')) {
            $this->line('Drive reported the target as not found. Check GOOGLE_DRIVE_FOLDER_ID (or');
            $this->line('GOOGLE_DRIVE_TEAM_DRIVE_ID) holds only the id from the folder URL, with no');
            $this->line('surrounding https://drive.google.com/drive/folders/ and no trailing slash.');
            $this->line('A folder that exists but is not shared with the account also reads as missing.');

            return;
        }

        if (str_contains($error, 'insufficientpermissions') || str_contains($error, '403')) {
            $this->line('Drive refused the write as unauthorised. Share the target with the service');
            $this->line("account's email (…@….iam.gserviceaccount.com) as Editor, or add it to the");
            $this->line('Shared Drive as Content manager. Sharing with your own account does not help:');
            $this->line('the service account is a separate identity.');

            return;
        }

        $this->line('Check, in order: that the Drive API is enabled for this project, that the target');
        $this->line("is shared with the service account's email as Editor, and that the id in .env is");
        $this->line('just the id from the folder URL. If folders appear in Drive but files do not, the');
        $this->line('cause is service-account storage quota -- use a Shared Drive.');
    }
}
