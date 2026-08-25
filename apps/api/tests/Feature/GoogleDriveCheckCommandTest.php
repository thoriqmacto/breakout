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
     * The command must not print the service-account path as "found" when it
     * is not, since that is the first thing anyone checks on a failing host.
     */
    public function test_it_reports_a_missing_key_file_without_resolving_the_disk(): void
    {
        config([
            'filesystems.disks.gdrive.keyFile' => 'storage/app/google/definitely-not-here.json',
            'filesystems.disks.gdrive.folderId' => 'some-folder-id',
        ]);

        $exit = Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('MISSING', $output);
        $this->assertStringContainsString('Google service-account file not found', $output);
    }

    public function test_it_reports_a_missing_folder_id(): void
    {
        // A real key file is not needed: folderId is validated by the driver too.
        config([
            'filesystems.disks.gdrive.keyFile' => 'composer.json',
            'filesystems.disks.gdrive.folderId' => '',
        ]);

        $exit = Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('(unset)', $output);
        $this->assertStringContainsString('GOOGLE_DRIVE_FOLDER_ID', $output);
    }

    public function test_it_does_not_print_the_credentials_themselves(): void
    {
        Storage::fake('gdrive');

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        // The folder id is reported only as set/unset, never echoed.
        $this->assertStringNotContainsString('private_key', $output);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $output);
    }

    /**
     * When a write fails the bare `false` is useless, so the command repeats it
     * against a throwing copy of the disk and prints the exception chain.
     *
     * Uses a driver whose adapter always fails to write. With throw => false
     * Laravel swallows that into `false`, and with throw => true it propagates
     * -- which is exactly the pair of behaviours the diagnosis relies on, and
     * what a Drive permission error looks like from the command's side.
     */
    public function test_it_surfaces_the_underlying_error_when_a_write_fails(): void
    {
        Storage::extend('always-fails', function ($app, array $config): FilesystemAdapter {
            $adapter = new class(storage_path('framework/testing/always-fails')) extends LocalFilesystemAdapter
            {
                public function write(string $path, string $contents, Config $config): void
                {
                    throw UnableToWriteFile::atLocation($path, 'the adapter refuses every write');
                }
            };

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        config(['filesystems.disks.wedged' => ['driver' => 'always-fails', 'throw' => false]]);

        $exit = Artisan::call('gdrive:check', ['--disk' => 'wedged']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Write failed', $output);
        $this->assertStringContainsString('Retrying with exceptions enabled', $output);
        // The real reason, rather than just "false".
        $this->assertStringContainsString('UnableToWriteFile', $output);
        $this->assertStringContainsString('refuses every write', $output);
    }

    public function test_it_prints_the_service_account_identity_but_not_the_private_key(): void
    {
        $keyFile = storage_path('framework/testing/service-account.json');
        @mkdir(dirname($keyFile), 0777, true);
        file_put_contents($keyFile, json_encode([
            'type' => 'service_account',
            'project_id' => 'breakout-demo-1234',
            'client_email' => 'writer@breakout-demo-1234.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----\n",
        ]));

        config([
            'filesystems.disks.gdrive.keyFile' => $keyFile,
            'filesystems.disks.gdrive.folderId' => 'some-folder',
        ]);

        Artisan::call('gdrive:check', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertStringContainsString('writer@breakout-demo-1234.iam.gserviceaccount.com', $output);
        $this->assertStringContainsString('breakout-demo-1234', $output);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $output);

        @unlink($keyFile);
    }
}
