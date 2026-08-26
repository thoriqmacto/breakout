<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
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
     * Why the last metadata fast path gave up, for gdrive:diagnose.
     *
     * The helpers swallow failures so a hiccup degrades to streaming rather
     * than breaking the page, but that turned every cause into a bare
     * "0 of 52". This carries a short, non-sensitive reason instead. It never
     * holds a credential: the messages are either fixed strings or an
     * exception class name.
     */
    private ?string $lastFailureReason = null;

    public function lastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

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
        $this->lastFailureReason = null;

        $adapter = $this->driveAdapter($disk);

        if ($adapter === null) {
            // Not a Drive disk; the caller hashes each file instead. Expected,
            // so it is not recorded as a failure.
            return [];
        }

        // Resolved through getMetadata(), which understands display paths.
        // Handing the raw display path to getFileObject() is what made this
        // return nothing in production.
        $folderId = $this->driveId($adapter, $directory);

        if ($folderId === null) {
            return [];
        }

        $folderId = str_replace("'", "\\'", $folderId);

        try {
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

            if ($checksums === []) {
                $this->lastFailureReason = "[{$directory}] resolved but Drive listed no checksummed files in it";
            }

            return $checksums;
        } catch (Throwable $e) {
            $this->lastFailureReason = 'listing threw '.class_basename($e);

            return [];
        }
    }

    /**
     * Resolve an ordinary Flysystem path to the Drive object's id.
     *
     * This is the fix for the fast path collapsing in production. Both helpers
     * used to call getFileObject($path) directly, but that method is not given
     * a display path -- it does splitPath() and takes the *last segment as a
     * Drive id*, so "seeds/historical" was looked up as the id "historical",
     * files.get 404'd, the catch swallowed it and every lookup reported
     * nothing. The result was "0 of 52" and a fallback that downloaded all 52
     * files to hash them, taking the backup report past the gateway timeout.
     *
     * getMetadata() is the public method that converts a display path to the
     * adapter's virtual path first, and normaliseObject() puts the real Drive
     * id in extraMetadata under 'id' for both files and directories. Reading
     * that is cheaper and safer than parsing the last segment out of
     * virtual_path by hand.
     */
    private function driveId(GoogleDriveAdapter $adapter, string $path): ?string
    {
        try {
            $metadata = $adapter->getMetadata($path);
        } catch (Throwable $e) {
            $this->lastFailureReason = 'path lookup threw '.class_basename($e);

            return null;
        }

        // getMetadata() answers false, not null, when it cannot resolve.
        if (! $metadata instanceof StorageAttributes) {
            $this->lastFailureReason = "no Drive object found for [{$path}]";

            return null;
        }

        $id = $metadata->extraMetadata()['id'] ?? null;

        if (! is_string($id) || $id === '') {
            $this->lastFailureReason = "resolved [{$path}] but its metadata carried no Drive id";

            return null;
        }

        return $id;
    }

    /**
     * Ask Drive for the checksum it already stores.
     *
     * The Flysystem adapter's own field list does not request md5Checksum, so
     * the resolved id is handed to a narrow files->get. Any failure returns
     * null so the caller falls back to hashing the stream.
     */
    private function driveChecksum(Filesystem $disk, string $path): ?string
    {
        $adapter = $this->driveAdapter($disk);

        if ($adapter === null) {
            return null;
        }

        $id = $this->driveId($adapter, $path);

        if ($id === null) {
            return null;
        }

        try {
            $meta = $adapter->getService()->files->get($id, [
                'fields' => 'md5Checksum',
                'supportsAllDrives' => true,
            ]);
            $checksum = method_exists($meta, 'getMd5Checksum') ? $meta->getMd5Checksum() : null;

            if (! is_string($checksum) || $checksum === '') {
                // Folders and Google-native documents genuinely have no MD5.
                $this->lastFailureReason = "Drive reports no md5Checksum for [{$path}]";

                return null;
            }

            return strtolower($checksum);
        } catch (Throwable $e) {
            $this->lastFailureReason = 'checksum lookup threw '.class_basename($e);

            return null;
        }
    }

    /**
     * The Google Drive adapter behind a disk, or null for any other disk.
     */
    private function driveAdapter(Filesystem $disk): ?GoogleDriveAdapter
    {
        if (! $disk instanceof FilesystemAdapter) {
            return null;
        }

        try {
            $adapter = $disk->getAdapter();
        } catch (Throwable) {
            return null;
        }

        return $adapter instanceof GoogleDriveAdapter ? $adapter : null;
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
