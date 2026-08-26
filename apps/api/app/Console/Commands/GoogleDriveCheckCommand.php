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
            // The gdrive driver validates its credentials and exchanges the
            // refresh token while resolving, so a missing variable or a
            // rejected grant surfaces here rather than at the first write.
            $this->error($e->getMessage());
            $this->hint($diskName, $e->getMessage());

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
     * Print what the disk resolved to, without echoing anything sensitive.
     * The client secret and refresh token are credentials, and the client id
     * has no diagnostic value beyond being present, so all three are reported
     * only as set or unset.
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

        foreach ([
            'client id' => 'clientId',
            'client secret' => 'clientSecret',
            'refresh token' => 'refreshToken',
        ] as $label => $key) {
            // Credentials are only ever reported as present or absent.
            $this->line(sprintf(
                '  %-15s %s',
                $label.':',
                trim((string) ($config[$key] ?? '')) === '' ? 'unset' : 'set',
            ));
        }

        $folderId = trim((string) ($config['folderId'] ?? ''));

        $this->line(sprintf('  %-15s %s', 'folder id:', $folderId === '' ? '(My Drive root)' : 'set'));
        $this->line(sprintf('  %-15s %s', 'root:', (string) ($config['root'] ?? 'breakout-data')));
        $this->newLine();

        return true;
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
     * guess. An earlier version always blamed one cause, which is actively
     * misleading when that cause is not the real one.
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

        if (str_contains($error, 'invalid_grant')) {
            $this->line('The refresh token was rejected. A refresh token stops working when it is');
            $this->line('revoked, when the account password or security settings change, when it no');
            $this->line('longer matches the OAuth client, or when it was issued while the consent');
            $this->line('screen was still in Testing -- those expire after seven days.');
            $this->newLine();
            $this->line('Generate a new one and put it in GOOGLE_DRIVE_REFRESH_TOKEN, then run');
            $this->line('php artisan config:cache. For a token that keeps working, move the OAuth');
            $this->line('consent screen out of Testing in the Google Cloud console.');

            return;
        }

        // Google answers a bad client with {"error": "invalid_client",
        // "error_description": "Unauthorized"}, so the bare word is matched too
        // for the case where only the description survives.
        if (str_contains($error, 'invalid_client') || str_contains($error, 'unauthorized_client')
            || str_contains($error, 'unauthorized')) {
            $this->line('Google rejected the OAuth client, not the refresh token. "Unauthorized" is');
            $this->line('the description it returns for invalid_client, and it means one of:');
            $this->newLine();
            $this->line('  - GOOGLE_DRIVE_CLIENT_SECRET does not match GOOGLE_DRIVE_CLIENT_ID;');
            $this->line('  - either value carries a stray space, quote or newline from being pasted');
            $this->line('    into .env -- check with: grep GOOGLE_DRIVE_CLIENT .env | cat -A');
            $this->line('  - the refresh token was minted by a *different* OAuth client, so the');
            $this->line('    three values do not belong to one credential;');
            $this->line('  - the client id is an Android/iOS/Desktop type rather than Web');
            $this->line('    application, which cannot use this grant.');
            $this->newLine();
            $this->line('Compare all three against the same credential in the Google Cloud console,');
            $this->line('then run php artisan config:cache before retrying.');

            return;
        }

        if (str_contains($error, 'has not been used') || str_contains($error, 'accessnotconfigured')
            || str_contains($error, 'api has not been enabled')) {
            $this->line('The Google Drive API is not enabled for the Cloud project behind this OAuth');
            $this->line('client. Enable it under APIs & Services, then retry -- it can take a minute');
            $this->line('to take effect.');

            return;
        }

        if (str_contains($error, 'insufficientpermissions') || str_contains($error, 'insufficient')
            || str_contains($error, '403')) {
            $this->line('The token was accepted but lacks the scope this needs. It must carry');
            $this->line('https://www.googleapis.com/auth/drive -- a read-only or drive.file scope is');
            $this->line('not enough. Re-authorise with that scope and regenerate the refresh token.');

            return;
        }

        if (str_contains($error, 'notfound') || str_contains($error, '404')) {
            $this->line('Drive reported the target as not found. If GOOGLE_DRIVE_FOLDER_ID is set,');
            $this->line('check it holds only the id from the folder URL and that the authorised');
            $this->line('account can open that folder. Leave it blank to use My Drive directly.');

            return;
        }

        $this->line('Check, in order: that the Google Drive API is enabled for the OAuth client\'s');
        $this->line('project, that the three GOOGLE_DRIVE_* credentials are the current ones, and');
        $this->line('that the refresh token was authorised with the full drive scope.');
    }
}
