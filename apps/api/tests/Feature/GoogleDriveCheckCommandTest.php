<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
}
