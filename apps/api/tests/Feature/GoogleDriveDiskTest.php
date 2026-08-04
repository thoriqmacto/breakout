<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Masbug\Flysystem\GoogleDriveAdapter;
use Tests\TestCase;

/**
 * Covers the "gdrive" driver registered by GoogleDriveServiceProvider.
 *
 * The round-trip test talks to a real Drive folder and is skipped unless
 * GOOGLE_DRIVE_KEY_FILE and GOOGLE_DRIVE_FOLDER_ID are both configured, so
 * CI and local development stay offline. Run it against a real shared folder
 * with:
 *
 *   GOOGLE_DRIVE_KEY_FILE=storage/app/google/service-account.json \
 *   GOOGLE_DRIVE_FOLDER_ID=<folder-id> \
 *   php artisan test --filter=GoogleDriveDiskTest
 */
class GoogleDriveDiskTest extends TestCase
{
    public function test_the_gdrive_disk_is_configured(): void
    {
        $disk = config('filesystems.disks.gdrive');

        $this->assertIsArray($disk, 'The gdrive disk is missing from config/filesystems.php.');
        $this->assertSame('gdrive', $disk['driver']);
        $this->assertArrayHasKey('keyFile', $disk);
        $this->assertArrayHasKey('folderId', $disk);
    }

    public function test_it_fails_with_an_actionable_message_when_the_key_file_is_missing(): void
    {
        config([
            'filesystems.disks.gdrive.keyFile' => null,
            'filesystems.disks.gdrive.folderId' => 'some-folder-id',
        ]);
        Storage::forgetDisk('gdrive');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_DRIVE_KEY_FILE');

        Storage::disk('gdrive');
    }

    public function test_it_fails_with_an_actionable_message_when_the_folder_id_is_missing(): void
    {
        config([
            'filesystems.disks.gdrive.keyFile' => __FILE__,
            'filesystems.disks.gdrive.folderId' => '',
        ]);
        Storage::forgetDisk('gdrive');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_DRIVE_FOLDER_ID');

        Storage::disk('gdrive');
    }

    public function test_it_round_trips_a_file_through_google_drive(): void
    {
        $this->skipUnlessDriveIsConfigured();

        $disk = Storage::disk('gdrive');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertInstanceOf(GoogleDriveAdapter::class, $disk->getAdapter());

        $path = 'smoke/round-trip-'.bin2hex(random_bytes(6)).'.txt';
        $contents = 'breakout gdrive smoke test '.now()->toIso8601String();

        try {
            $this->assertTrue($disk->put($path, $contents), 'Failed to write to Google Drive.');
            $this->assertTrue($disk->exists($path));
            $this->assertSame($contents, $disk->get($path));
        } finally {
            $disk->delete($path);
        }

        $this->assertFalse($disk->exists($path), 'The smoke-test file was not deleted from Google Drive.');
    }

    private function skipUnlessDriveIsConfigured(): void
    {
        $keyFile = (string) config('filesystems.disks.gdrive.keyFile');
        $folderId = (string) config('filesystems.disks.gdrive.folderId');

        $path = $keyFile !== '' && ! str_starts_with($keyFile, DIRECTORY_SEPARATOR)
            ? base_path($keyFile)
            : $keyFile;

        if ($path === '' || ! is_file($path) || $folderId === '') {
            $this->markTestSkipped(
                'Google Drive credentials are not configured; set GOOGLE_DRIVE_KEY_FILE and GOOGLE_DRIVE_FOLDER_ID to run the round-trip smoke test.'
            );
        }
    }
}
