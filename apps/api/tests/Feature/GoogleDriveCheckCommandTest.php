<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

class GoogleDriveCheckCommandTest extends TestCase
{
    public function test_it_reports_success_against_a_working_disk(): void
    {
        Storage::fake('gdrive');

        $exit = Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('write ......... ok', $output);
        $this->assertStringContainsString('read .......... ok', $output);
        $this->assertStringContainsString('overwrite ..... ok', $output);
        $this->assertStringContainsString('delete ........ ok', $output);
        $this->assertStringContainsString('is working', $output);
    }

    public function test_it_leaves_nothing_behind_on_success(): void
    {
        Storage::fake('gdrive');

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);

        $this->assertSame([], Storage::disk('gdrive')->files('smoke'));
    }

    public function test_keep_retains_the_probe_file(): void
    {
        Storage::fake('gdrive');

        $exit = Artisan::call('gdrive:check', ['--disk' => 'gdrive', '--keep' => true]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, Storage::disk('gdrive')->files('smoke'));
    }

    public function test_it_fails_for_a_disk_that_does_not_exist(): void
    {
        $exit = Artisan::call('gdrive:check', ['--disk' => 'not-a-real-disk']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no disk named', Artisan::output());
    }

    /**
     * Credentials are reported only as present or absent, and a blank folder
     * id is normal rather than an error -- My Drive is the default target.
     */
    public function test_it_reports_credentials_as_set_or_unset(): void
    {
        config([
            'filesystems.disks.gdrive.driver' => 'gdrive',
            'filesystems.disks.gdrive.clientId' => 'an-id.apps.googleusercontent.com',
            'filesystems.disks.gdrive.clientSecret' => 'a-secret',
            'filesystems.disks.gdrive.refreshToken' => '',
            'filesystems.disks.gdrive.folderId' => '',
            'filesystems.disks.gdrive.root' => 'breakout-data',
        ]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringContainsString('client id:', $output);
        $this->assertStringContainsString('client secret:', $output);
        $this->assertStringContainsString('refresh token:', $output);
        $this->assertStringContainsString('unset', $output);
        $this->assertStringContainsString('(My Drive root)', $output);
        $this->assertStringContainsString('breakout-data', $output);
    }

    public function test_it_never_prints_the_credential_values(): void
    {
        config([
            'filesystems.disks.gdrive.driver' => 'gdrive',
            'filesystems.disks.gdrive.clientId' => 'client-id-value',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret-value',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token-value',
        ]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringNotContainsString('client-secret-value', $output);
        $this->assertStringNotContainsString('refresh-token-value', $output);
        $this->assertStringNotContainsString('client-id-value', $output);
    }

    public function test_a_missing_credential_names_its_environment_variable(): void
    {
        config([
            'filesystems.disks.gdrive.driver' => 'gdrive',
            'filesystems.disks.gdrive.clientId' => '',
            'filesystems.disks.gdrive.clientSecret' => 'a-secret',
            'filesystems.disks.gdrive.refreshToken' => 'a-token',
        ]);
        Storage::forgetDisk('gdrive');

        $exit = Artisan::call('gdrive:check', ['--disk' => 'gdrive']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('GOOGLE_DRIVE_CLIENT_ID', Artisan::output());
    }

    /**
     * When a write fails the bare `false` is useless, so the command repeats it
     * against a throwing copy of the disk and prints the exception chain.
     *
     * Uses a driver whose adapter always fails to write. With throw => false
     * Laravel swallows that into `false`, and with throw => true it propagates
     * -- exactly the pair of behaviours the diagnosis relies on.
     */
    public function test_it_surfaces_the_underlying_error_when_a_write_fails(): void
    {
        $this->registerFailingDriver('always-fails', 'the adapter refuses every write');

        config(['filesystems.disks.wedged' => ['driver' => 'always-fails', 'throw' => false]]);

        $exit = Artisan::call('gdrive:check', ['--disk' => 'wedged']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Write failed', $output);
        $this->assertStringContainsString('Retrying with exceptions enabled', $output);
        $this->assertStringContainsString('UnableToWriteFile', $output);
        $this->assertStringContainsString('refuses every write', $output);
    }

    /**
     * The hint must follow what Google said. A rejected refresh token is the
     * failure an operator is most likely to hit, and it needs its own advice.
     */
    public function test_an_invalid_grant_recommends_regenerating_the_refresh_token(): void
    {
        $this->registerFailingDriver('invalid-grant', '(400) invalid_grant: Token has been expired or revoked.');
        config(['filesystems.disks.gdrive' => ['driver' => 'invalid-grant', 'throw' => false]]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringContainsString('GOOGLE_DRIVE_REFRESH_TOKEN', $output);
        $this->assertStringContainsString('Testing', $output);
        // Service-account advice must never appear again.
        $this->assertStringNotContainsString('Shared Drive', $output);
        $this->assertStringNotContainsString('iam.gserviceaccount.com', $output);
    }

    public function test_an_invalid_client_points_at_the_id_and_secret(): void
    {
        $this->registerFailingDriver('invalid-client', '(401) invalid_client: The OAuth client was not found.');
        config(['filesystems.disks.gdrive' => ['driver' => 'invalid-client', 'throw' => false]]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringContainsString('GOOGLE_DRIVE_CLIENT_ID', $output);
        $this->assertStringContainsString('GOOGLE_DRIVE_CLIENT_SECRET', $output);
    }

    public function test_insufficient_permissions_names_the_required_scope(): void
    {
        $this->registerFailingDriver('no-scope', '(403) insufficientPermissions: Insufficient Permission');
        config(['filesystems.disks.gdrive' => ['driver' => 'no-scope', 'throw' => false]]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);

        $this->assertStringContainsString(
            'https://www.googleapis.com/auth/drive',
            Artisan::output(),
        );
    }

    public function test_a_disabled_api_says_so(): void
    {
        $this->registerFailingDriver(
            'api-off',
            'Google Drive API has not been used in project 123 before or it is disabled.',
        );
        config(['filesystems.disks.gdrive' => ['driver' => 'api-off', 'throw' => false]]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);

        $this->assertStringContainsString('not enabled', Artisan::output());
    }

    public function test_a_not_found_error_points_at_the_configured_folder_id(): void
    {
        $this->registerFailingDriver('not-found', '(404) notFound: File not found: abc123.');
        config(['filesystems.disks.gdrive' => ['driver' => 'not-found', 'throw' => false]]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringContainsString('not found', $output);
        $this->assertStringContainsString('GOOGLE_DRIVE_FOLDER_ID', $output);
    }

    /**
     * Registers a disk driver whose adapter refuses every write with the given
     * message, standing in for a Drive API rejection.
     */
    private function registerFailingDriver(string $name, string $reason): void
    {
        Storage::extend($name, function ($app, array $config) use ($name, $reason): FilesystemAdapter {
            $adapter = new class(storage_path('framework/testing/'.$name), $reason) extends LocalFilesystemAdapter
            {
                public function __construct(string $root, private readonly string $reason)
                {
                    parent::__construct($root);
                }

                public function write(string $path, string $contents, Config $config): void
                {
                    throw UnableToWriteFile::atLocation($path, $this->reason);
                }
            };

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
