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
