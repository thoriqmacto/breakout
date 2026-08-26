<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the "gdrive" driver registered by GoogleDriveServiceProvider.
 *
 * Authentication is OAuth against a personal Google account. The round-trip
 * test talks to real Drive and is skipped unless the three credentials are
 * configured, so CI and local development stay offline. Run it for real with:
 *
 *   GOOGLE_DRIVE_CLIENT_ID=... \
 *   GOOGLE_DRIVE_CLIENT_SECRET=... \
 *   GOOGLE_DRIVE_REFRESH_TOKEN=... \
 *   php artisan test --filter=GoogleDriveDiskTest
 */
class GoogleDriveDiskTest extends TestCase
{
    public function test_the_gdrive_disk_is_configured(): void
    {
        $disk = config('filesystems.disks.gdrive');

        $this->assertIsArray($disk, 'The gdrive disk is missing from config/filesystems.php.');
        $this->assertSame('gdrive', $disk['driver']);
        $this->assertArrayHasKey('clientId', $disk);
        $this->assertArrayHasKey('clientSecret', $disk);
        $this->assertArrayHasKey('refreshToken', $disk);
        $this->assertArrayHasKey('folderId', $disk);
        $this->assertArrayHasKey('root', $disk);
    }

    /**
     * The service-account era is over: nothing should still be reaching for a
     * credentials file or a Shared Drive id.
     */
    public function test_the_disk_no_longer_carries_service_account_configuration(): void
    {
        $disk = config('filesystems.disks.gdrive');

        $this->assertArrayNotHasKey('keyFile', $disk);
        $this->assertArrayNotHasKey('teamDriveId', $disk);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            'client id' => ['clientId', 'GOOGLE_DRIVE_CLIENT_ID'],
            'client secret' => ['clientSecret', 'GOOGLE_DRIVE_CLIENT_SECRET'],
            'refresh token' => ['refreshToken', 'GOOGLE_DRIVE_REFRESH_TOKEN'],
        ];
    }

    #[DataProvider('missingCredentialProvider')]
    public function test_each_missing_credential_names_its_environment_variable(
        string $key,
        string $envVar,
    ): void {
        config([
            'filesystems.disks.gdrive.clientId' => 'client-id',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token',
            "filesystems.disks.gdrive.{$key}" => '',
        ]);
        Storage::forgetDisk('gdrive');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($envVar);

        Storage::disk('gdrive');
    }

    /**
     * My Drive is a valid target, so a blank folder id must not be treated as
     * a configuration error. Resolution still fails here -- the fake
     * credentials cannot be exchanged for a token -- but it must fail on the
     * OAuth exchange rather than on validation.
     */
    public function test_a_blank_folder_id_is_not_a_configuration_error(): void
    {
        config([
            'filesystems.disks.gdrive.clientId' => 'client-id',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token',
            'filesystems.disks.gdrive.folderId' => '',
        ]);
        Storage::forgetDisk('gdrive');

        try {
            Storage::disk('gdrive');
            $this->fail('Expected the fake credentials to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('GOOGLE_DRIVE_FOLDER_ID', $e->getMessage());
            $this->assertStringContainsString('OAuth', $e->getMessage());
        }
    }

    /**
     * Whatever the OAuth failure, the message must not carry the credentials
     * that were sent with the request.
     */
    public function test_an_oauth_failure_does_not_leak_the_credentials(): void
    {
        config([
            'filesystems.disks.gdrive.clientId' => 'client-id-value',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret-value',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token-value',
        ]);
        Storage::forgetDisk('gdrive');

        try {
            Storage::disk('gdrive');
            $this->fail('Expected the fake credentials to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('client-secret-value', $e->getMessage());
            $this->assertStringNotContainsString('refresh-token-value', $e->getMessage());
        }
    }

    public function test_it_round_trips_a_file_through_google_drive(): void
    {
        $this->skipUnlessDriveIsConfigured();

        $disk = Storage::disk('gdrive');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertInstanceOf(GoogleDriveAdapter::class, $disk->getAdapter());

        $path = 'smoke/round-trip-'.bin2hex(random_bytes(6)).'.txt';
        $contents = 'breakout gdrive smoke test '.now()->toIso8601String();
        $overwritten = $contents.' (overwritten)';

        try {
            $this->assertTrue($disk->put($path, $contents), 'Failed to write to Google Drive.');
            $this->assertTrue($disk->exists($path));
            $this->assertSame($contents, $disk->get($path));

            // Drive permits duplicate names in a folder, so replacement is not
            // something a local filesystem can stand in for.
            $this->assertTrue($disk->put($path, $overwritten), 'Failed to overwrite on Google Drive.');
            $this->assertSame($overwritten, $disk->get($path));
            $this->assertCount(
                1,
                collect($disk->files('smoke'))->filter(static fn ($f): bool => $f === $path)->all(),
                'The overwrite created a duplicate instead of replacing the file.',
            );
        } finally {
            $disk->delete($path);
        }

        $this->assertFalse($disk->exists($path), 'The smoke-test file was not deleted from Google Drive.');
    }

    private function skipUnlessDriveIsConfigured(): void
    {
        $clientId = trim((string) config('filesystems.disks.gdrive.clientId'));
        $clientSecret = trim((string) config('filesystems.disks.gdrive.clientSecret'));
        $refreshToken = trim((string) config('filesystems.disks.gdrive.refreshToken'));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            $this->markTestSkipped(
                'Google Drive OAuth credentials are not configured; set GOOGLE_DRIVE_CLIENT_ID, '.
                'GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REFRESH_TOKEN to run the round-trip smoke test.'
            );
        }
    }
}
