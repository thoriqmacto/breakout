<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Masbug\Flysystem\GoogleDriveAdapter;
use Throwable;

/**
 * Content hashes for backup comparison.
 *
 * MD5 throughout, and deliberately so: Google Drive exposes an md5Checksum on
 * every ordinary binary file, so choosing MD5 lets a Drive file be compared
 * against a local one without downloading it. A stronger digest would be
 * pointless here anyway -- this detects drift between two copies of our own
 * data, not tampering.
 */
class ContentHasher
{
    /**
     * Bytes read per chunk when hashing a remote stream.
     */
    private const CHUNK = 1_048_576;

    /**
     * MD5 of a local file, or null when it cannot be read.
     */
    public function local(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = @md5_file($path);

        return $hash === false ? null : $hash;
    }

    /**
     * MD5 of a file on a disk.
     *
     * Google Drive already knows the checksum, so ask for it rather than
     * pulling the file across the wire. Every other disk -- and Drive when the
     * metadata call fails or the file has no checksum, as folders and Google
     * Docs do not -- falls back to hashing the stream in chunks, which is
     * correct everywhere but pays a full download.
     */
    public function remote(Filesystem $disk, string $path): ?string
    {
        return $this->driveChecksum($disk, $path) ?? $this->streamed($disk, $path);
    }

    /**
     * Every checksum in a Drive folder, in one query.
     *
     * Asking per file costs two round trips each -- resolve the path to an id,
     * then fetch the metadata -- and thirty files was enough to push the
     * backup-status request past the gateway timeout. Drive will return the
     * whole folder's metadata, checksums included, in a single listing, so it
     * is asked once and the answers are matched up by name.
     *
     * Returns an empty array for any disk that is not Drive, or when the query
     * fails; the caller then falls back to hashing each file individually.
     *
     * @return array<string, string> Filename (as it appears in the folder) to MD5.
     */
    public function directoryChecksums(Filesystem $disk, string $directory): array
    {
        if (! $disk instanceof FilesystemAdapter) {
            return [];
        }

        try {
            $adapter = $disk->getAdapter();

            if (! $adapter instanceof GoogleDriveAdapter) {
                return [];
            }

            $folder = $adapter->getFileObject($directory);

            if ($folder === null || ! method_exists($folder, 'getId')) {
                return [];
            }

            $folderId = str_replace("'", "\\'", (string) $folder->getId());

            if ($folderId === '') {
                return [];
            }

            $service = $adapter->getService();
            $checksums = [];
            $pageToken = null;
            $pages = 0;

            do {
                $response = $service->files->listFiles([
                    'q' => "'{$folderId}' in parents and trashed = false",
                    'fields' => 'nextPageToken, files(name, md5Checksum)',
                    'pageSize' => 1000,
                    'pageToken' => $pageToken,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                ]);

                foreach ($response->getFiles() as $file) {
                    $checksum = method_exists($file, 'getMd5Checksum') ? $file->getMd5Checksum() : null;

                    if (is_string($checksum) && $checksum !== '') {
                        $checksums[(string) $file->getName()] = strtolower($checksum);
                    }
                }

                $pageToken = $response->getNextPageToken();
                // A guard, not a limit: 1000 per page means a folder would need
                // 20,000 files to reach this, and an unbounded loop against a
                // paging bug is exactly what a request timeout looks like.
            } while ($pageToken !== null && ++$pages < 20);

            return $checksums;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Ask Drive for the checksum it already stores.
     *
     * The Flysystem adapter's own field list does not request md5Checksum, so
     * the id it resolves is handed to a narrow files->get. Both getFileObject()
     * and getService() are public adapter API; nothing here reaches into
     * internals. Any failure returns null so the caller falls back to hashing.
     */
    private function driveChecksum(Filesystem $disk, string $path): ?string
    {
        if (! $disk instanceof FilesystemAdapter) {
            return null;
        }

        try {
            $adapter = $disk->getAdapter();

            if (! $adapter instanceof GoogleDriveAdapter) {
                return null;
            }

            $file = $adapter->getFileObject($path);

            if ($file === null || ! method_exists($file, 'getId')) {
                return null;
            }

            $id = (string) $file->getId();

            if ($id === '') {
                return null;
            }

            $meta = $adapter->getService()->files->get($id, ['fields' => 'md5Checksum']);
            $checksum = method_exists($meta, 'getMd5Checksum') ? $meta->getMd5Checksum() : null;

            return is_string($checksum) && $checksum !== '' ? strtolower($checksum) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * MD5 of a disk file, read in chunks so a large CSV never lands in memory
     * whole.
     */
    private function streamed(Filesystem $disk, string $path): ?string
    {
        $stream = null;

        try {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return null;
            }

            $context = hash_init('md5');

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);

                if ($chunk === false) {
                    return null;
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
