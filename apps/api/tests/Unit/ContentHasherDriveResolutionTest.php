<?php

namespace Tests\Unit;

use App\Services\ContentHasher;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem as Flysystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use Tests\TestCase;

/**
 * Display-path resolution for the Drive checksum fast path.
 *
 * The fast path collapsed in production -- "0 of 52 files" -- because both
 * helpers handed an ordinary Flysystem path to GoogleDriveAdapter::
 * getFileObject(). That method does not take a display path: it splits on "/"
 * and treats the last segment as a Drive id, so "seeds/historical" was looked
 * up as the id "historical". files.get 404'd, the catch swallowed it, and
 * every file fell through to being downloaded and hashed -- 89 seconds for the
 * report, past the gateway timeout.
 *
 * getMetadata() is the public method that converts the display path first, and
 * exposes the real id in extraMetadata. These tests pin that, with the adapter
 * mocked so nothing here needs a Google account.
 */
class ContentHasherDriveResolutionTest extends TestCase
{
    /**
     * A Laravel disk whose underlying adapter is the given Drive adapter.
     */
    private function driveDisk(GoogleDriveAdapter $adapter): LaravelFilesystemAdapter
    {
        return new LaravelFilesystemAdapter(new Flysystem($adapter), $adapter, []);
    }

    /**
     * The metadata shape normaliseObject() produces: the Drive id lives in
     * extraMetadata under 'id'.
     */
    private function attributes(string $path, string $id): FileAttributes
    {
        return new FileAttributes($path, null, null, null, null, [
            'id' => $id,
            'virtual_path' => "parentId/{$id}",
            'display_path' => $path,
        ]);
    }

    public function test_a_directory_display_path_is_resolved_through_get_metadata(): void
    {
        $adapter = $this->createMock(GoogleDriveAdapter::class);

        // The assertion that matters: the *display* path is what gets resolved,
        // and it goes through getMetadata(), not getFileObject().
        $adapter->expects($this->once())
            ->method('getMetadata')
            ->with('seeds/historical')
            ->willReturn($this->attributes('seeds/historical', 'folder-id-123'));

        $adapter->expects($this->never())->method('getFileObject');

        // getService() throwing stands in for the network call; resolution has
        // already happened by then, which is what this test is about.
        $adapter->method('getService')->willThrowException(new \RuntimeException('no network'));

        $hasher = new ContentHasher;
        $this->assertSame([], $hasher->directoryChecksums($this->driveDisk($adapter), 'seeds/historical'));

        // Resolution succeeded, so the failure recorded is the listing, not the
        // path -- the distinction the production "0 of 52" could not make.
        $this->assertStringContainsString('listing threw', (string) $hasher->lastFailureReason());
    }

    public function test_an_unresolvable_directory_is_reported_as_such(): void
    {
        $adapter = $this->createMock(GoogleDriveAdapter::class);

        // getMetadata answers false, not null, when it cannot resolve.
        $adapter->method('getMetadata')->willReturn(false);

        $hasher = new ContentHasher;

        $this->assertSame([], $hasher->directoryChecksums($this->driveDisk($adapter), 'seeds/nope'));
        $this->assertStringContainsString('no Drive object found', (string) $hasher->lastFailureReason());
        $this->assertStringContainsString('seeds/nope', (string) $hasher->lastFailureReason());
    }

    public function test_metadata_without_an_id_is_reported_rather_than_guessed(): void
    {
        $adapter = $this->createMock(GoogleDriveAdapter::class);
        $adapter->method('getMetadata')->willReturn(
            new FileAttributes('seeds/historical', null, null, null, null, ['virtual_path' => 'x/y'])
        );

        $hasher = new ContentHasher;

        $this->assertSame([], $hasher->directoryChecksums($this->driveDisk($adapter), 'seeds/historical'));
        $this->assertStringContainsString('no Drive id', (string) $hasher->lastFailureReason());
    }

    public function test_a_file_display_path_is_resolved_the_same_way(): void
    {
        $adapter = $this->createMock(GoogleDriveAdapter::class);

        $adapter->expects($this->once())
            ->method('getMetadata')
            ->with('seeds/historical/BBCA.csv')
            ->willReturn($this->attributes('seeds/historical/BBCA.csv', 'file-id-456'));

        $adapter->expects($this->never())->method('getFileObject');
        $adapter->method('getService')->willThrowException(new \RuntimeException('no network'));

        $hasher = new ContentHasher;

        // Falls back to streaming, which the mocked adapter also refuses --
        // the point is that resolution used getMetadata().
        $hasher->remote($this->driveDisk($adapter), 'seeds/historical/BBCA.csv');

        $this->assertNotNull($hasher->lastFailureReason());
    }

    /**
     * A resolution failure must not be silent, but it also must not be
     * indistinguishable from an empty folder.
     */
    public function test_an_empty_folder_is_distinguished_from_a_failed_lookup(): void
    {
        $adapter = $this->createMock(GoogleDriveAdapter::class);
        $adapter->method('getMetadata')->willReturn($this->attributes('seeds/historical', 'folder-id'));
        $adapter->method('getService')->willThrowException(new \RuntimeException('boom'));

        $hasher = new ContentHasher;
        $hasher->directoryChecksums($this->driveDisk($adapter), 'seeds/historical');

        $this->assertStringNotContainsString('no Drive object found', (string) $hasher->lastFailureReason());
    }

    /**
     * Every other disk keeps hashing streams, and that is not a failure.
     */
    public function test_a_non_drive_disk_uses_streamed_hashing_without_recording_a_failure(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('seeds/historical/AAA.csv', 'date,close');

        $hasher = new ContentHasher;
        $disk = Storage::disk('local');

        $this->assertSame([], $hasher->directoryChecksums($disk, 'seeds/historical'));
        $this->assertNull($hasher->lastFailureReason());

        // The stream fallback still produces a correct MD5.
        $this->assertSame(md5('date,close'), $hasher->remote($disk, 'seeds/historical/AAA.csv'));
    }

    public function test_streamed_hashing_matches_md5(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('x.csv', 'hello world');

        $this->assertSame(md5('hello world'), (new ContentHasher)->remote(Storage::disk('local'), 'x.csv'));
    }

    public function test_a_missing_remote_file_hashes_to_null(): void
    {
        Storage::fake('local');

        $this->assertNull((new ContentHasher)->remote(Storage::disk('local'), 'absent.csv'));
    }
}
