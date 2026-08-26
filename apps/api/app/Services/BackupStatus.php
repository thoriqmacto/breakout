<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compares the local backup files against their Google Drive copies.
 *
 * The first version of this called a file "synced" whenever the same *name*
 * existed in both places. That is not synchronisation. After
 * `stockbit:scrape BBCA --historical` extends the local CSV, the Drive copy
 * still holds yesterday's data under the same name, and the page reported it
 * green -- exactly the case a backup status page exists to catch. States here
 * are decided on content, and "synced" is only ever returned once two files
 * have been shown to hold the same bytes.
 */
class BackupStatus
{
    /** Both copies exist and are byte-identical. */
    public const SYNCED = 'synced';

    /** Only the local copy exists. */
    public const LOCAL_ONLY = 'local_only';

    /** Only the Drive copy exists. */
    public const GDRIVE_ONLY = 'gdrive_only';

    /** Contents differ; local was modified more recently. */
    public const LOCAL_NEWER = 'local_newer';

    /** Contents differ; Drive was modified more recently. */
    public const GDRIVE_NEWER = 'gdrive_newer';

    /** Contents differ and the timestamps do not settle which is newer. */
    public const DIFFERENT = 'different';

    /** Both copies exist but could not be compared. Never treated as synced. */
    public const COMPARE_ERROR = 'compare_error';

    /**
     * States a push resolves by making Drive match local.
     *
     * DIFFERENT is included because the local copy is the working copy, but the
     * direction is genuinely unclear, so the UI confirms before pushing it.
     */
    private const PUSHABLE = [self::LOCAL_ONLY, self::LOCAL_NEWER, self::DIFFERENT];

    public function __construct(
        private readonly ContentHasher $hasher,
        private readonly GoogleDriveHealth $health,
    ) {}

    /**
     * @return array{
     *     generated_at:string,
     *     google_drive:array<string, mixed>,
     *     locations:array<int, array<string, mixed>>,
     *     collections:array<int, array<string, mixed>>
     * }
     */
    public function report(string $remoteDisk = 'gdrive'): array
    {
        $health = $this->health->check($remoteDisk);
        $remote = $health['can_read'] ? $this->disk($remoteDisk) : null;

        $historicalRemote = $this->diskFiles(
            $remote,
            trim((string) config('csv.mirror_path', 'seeds/historical'), '/'),
            ['csv'],
        );

        $brokerDirectory = trim((string) config('stockbit.save_dir', 'broker_summary'), '/');

        $collections = [
            $this->collection(
                'historical',
                'Historical prices',
                $this->localDirectoryFiles((string) config('csv.seed_dir'), ['csv']),
                $historicalRemote,
                $remote,
                // Historical CSVs are the only collection with a mirror
                // service (BarCsvMirror), so they are the only one this page
                // can offer to push.
                pushable: true,
            ),
            $this->collection(
                'broker_summary',
                'Broker summary',
                $this->diskFiles($this->disk('local'), $brokerDirectory, ['csv', 'json']),
                $this->diskFiles($remote, $brokerDirectory, ['csv', 'json']),
                $remote,
                pushable: false,
            ),
        ];

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'google_drive' => $health,
            'locations' => [
                ['key' => 'local', 'label' => 'Local', 'available' => true, 'scan_status' => 'ok'],
                [
                    'key' => 'gdrive',
                    'label' => 'Google Drive',
                    'available' => $health['can_read'],
                    'scan_status' => $historicalRemote['status'],
                ],
            ],
            'collections' => $collections,
        ];
    }

    /**
     * States that a mirror push can resolve, per symbol, for one collection.
     *
     * The push endpoint uses this so the set of files it will touch is derived
     * from the same comparison the page displayed, rather than from anything
     * the browser sends.
     *
     * @return array<int, string>
     */
    public function pushableNames(array $collection): array
    {
        $names = [];

        foreach ($collection['files'] ?? [] as $file) {
            if (($file['can_push'] ?? false) === true) {
                $names[] = $file['name'];
            }
        }

        return $names;
    }

    /**
     * Local files in an absolute directory, keyed by filename.
     *
     * @param  array<int, string>  $extensions
     * @return array{status:string, files:array<string, array<string, mixed>>}
     */
    private function localDirectoryFiles(string $directory, array $extensions): array
    {
        $files = [];

        foreach (glob(rtrim($directory, '/'.DIRECTORY_SEPARATOR).'/*') ?: [] as $path) {
            if (! is_file($path) || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }

            $modified = filemtime($path);
            $size = filesize($path);

            $files[basename($path)] = [
                'path' => $path,
                'size' => $size === false ? null : $size,
                'modified_at' => $modified === false ? null : Carbon::createFromTimestamp($modified)->toIso8601String(),
                'timestamp' => $modified === false ? null : $modified,
                'local' => true,
            ];
        }

        return ['status' => 'ok', 'files' => $files];
    }

    /**
     * Files on a disk under a directory, keyed by name relative to it.
     *
     * The status is the important part. The previous version returned a bare
     * [] when the listing threw, so a rejected OAuth grant was indistinguishable
     * from a Drive folder that happens to be empty -- and every local file was
     * then reported as "local only", which reads as "your backups are missing"
     * when the truth is "we could not look".
     *
     * @param  array<int, string>  $extensions
     * @return array{status:string, files:array<string, array<string, mixed>>}
     */
    private function diskFiles(?Filesystem $disk, string $directory, array $extensions): array
    {
        if ($disk === null) {
            return ['status' => 'unavailable', 'files' => []];
        }

        try {
            $paths = $disk->allFiles($directory);
        } catch (Throwable) {
            return ['status' => 'failed', 'files' => []];
        }

        $files = [];

        foreach ($paths as $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }

            $name = ltrim(substr($path, strlen($directory)), '/');

            try {
                $timestamp = $disk->lastModified($path);
                $size = $disk->size($path);
            } catch (Throwable) {
                $timestamp = null;
                $size = null;
            }

            $files[$name] = [
                'path' => $path,
                'size' => $size,
                'modified_at' => $timestamp ? Carbon::createFromTimestamp($timestamp)->toIso8601String() : null,
                'timestamp' => $timestamp,
                'local' => false,
            ];
        }

        return ['status' => 'ok', 'files' => $files];
    }

    /**
     * @param  array{status:string, files:array<string, array<string, mixed>>}  $local
     * @param  array{status:string, files:array<string, array<string, mixed>>}  $remote
     * @return array<string, mixed>
     */
    private function collection(
        string $key,
        string $label,
        array $local,
        array $remote,
        ?Filesystem $remoteDisk,
        bool $pushable,
    ): array {
        $localFiles = $local['files'];
        $remoteFiles = $remote['files'];
        $remoteScanned = $remote['status'] === 'ok';

        $names = array_values(array_unique([...array_keys($localFiles), ...array_keys($remoteFiles)]));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $files = [];

        foreach ($names as $name) {
            $files[] = $this->describe(
                $name,
                $localFiles[$name] ?? null,
                $remoteFiles[$name] ?? null,
                $remoteDisk,
                $remoteScanned,
                $pushable,
            );
        }

        return [
            'key' => $key,
            'label' => $label,
            'pushable' => $pushable,
            'scan' => ['local' => $local['status'], 'gdrive' => $remote['status']],
            'counts' => $this->counts($files, $localFiles, $remoteFiles),
            'files' => $files,
        ];
    }

    /**
     * Decide one file's state.
     *
     * @param  array<string, mixed>|null  $local
     * @param  array<string, mixed>|null  $remote
     * @return array<string, mixed>
     */
    private function describe(
        string $name,
        ?array $local,
        ?array $remote,
        ?Filesystem $remoteDisk,
        bool $remoteScanned,
        bool $pushable,
    ): array {
        // Drive could not be listed. Absence proves nothing, so the file is
        // unknown rather than local-only, and nothing is offered as pushable.
        if (! $remoteScanned) {
            return $this->entry($name, self::COMPARE_ERROR, $local, null, false);
        }

        if ($local !== null && $remote === null) {
            return $this->entry($name, self::LOCAL_ONLY, $local, null, $pushable);
        }

        if ($local === null && $remote !== null) {
            return $this->entry($name, self::GDRIVE_ONLY, null, $remote, false);
        }

        if ($local === null || $remote === null) {
            return $this->entry($name, self::COMPARE_ERROR, $local, $remote, false);
        }

        $state = $this->compare($local, $remote, $remoteDisk, $localHash, $remoteHash);

        $local['hash'] = $localHash;
        $remote['hash'] = $remoteHash;

        return $this->entry($name, $state, $local, $remote, $pushable && in_array($state, self::PUSHABLE, true));
    }

    /**
     * Compare two existing copies.
     *
     * Differing sizes settle it without hashing. Equal sizes do not: two files
     * of the same length are routinely different, so the hash is still needed.
     *
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $remote
     */
    private function compare(
        array $local,
        array $remote,
        ?Filesystem $remoteDisk,
        ?string &$localHash,
        ?string &$remoteHash,
    ): string {
        $localHash = null;
        $remoteHash = null;

        $localSize = $local['size'] ?? null;
        $remoteSize = $remote['size'] ?? null;

        if (is_int($localSize) && is_int($remoteSize) && $localSize !== $remoteSize) {
            return $this->byTimestamp($local, $remote);
        }

        if ($remoteDisk === null) {
            return self::COMPARE_ERROR;
        }

        $localHash = $this->hasher->local((string) $local['path']);
        $remoteHash = $this->hasher->remote($remoteDisk, (string) $remote['path']);

        // An unreadable side means unknown. Reporting that as synced is the
        // one failure mode this page must never have.
        if ($localHash === null || $remoteHash === null) {
            return self::COMPARE_ERROR;
        }

        if ($localHash === $remoteHash) {
            return self::SYNCED;
        }

        return $this->byTimestamp($local, $remote);
    }

    /**
     * Direction of a known difference. Equal or missing timestamps leave it
     * unresolved rather than guessing.
     *
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $remote
     */
    private function byTimestamp(array $local, array $remote): string
    {
        $localTime = $local['timestamp'] ?? null;
        $remoteTime = $remote['timestamp'] ?? null;

        if (! is_int($localTime) || ! is_int($remoteTime)) {
            return self::DIFFERENT;
        }

        return match (true) {
            $localTime > $remoteTime => self::LOCAL_NEWER,
            $remoteTime > $localTime => self::GDRIVE_NEWER,
            default => self::DIFFERENT,
        };
    }

    /**
     * @param  array<string, mixed>|null  $local
     * @param  array<string, mixed>|null  $remote
     * @return array<string, mixed>
     */
    private function entry(string $name, string $state, ?array $local, ?array $remote, bool $canPush): array
    {
        return [
            'name' => $name,
            'state' => $state,
            'can_push' => $canPush,
            'local' => $local === null ? null : $this->publicInfo($local),
            'gdrive' => $remote === null ? null : $this->publicInfo($remote),
        ];
    }

    /**
     * The shape sent to the browser. `timestamp` is dropped as redundant with
     * modified_at; the hash is kept because a short prefix of it is genuinely
     * useful when diagnosing a mismatch, and a content hash of our own market
     * data is not sensitive.
     *
     * @param  array<string, mixed>  $file
     * @return array{path:string, size:?int, modified_at:?string, hash:?string}
     */
    private function publicInfo(array $file): array
    {
        return [
            'path' => (string) ($file['path'] ?? ''),
            'size' => $file['size'] ?? null,
            'modified_at' => $file['modified_at'] ?? null,
            'hash' => $file['hash'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, array<string, mixed>>  $localFiles
     * @param  array<string, array<string, mixed>>  $remoteFiles
     * @return array<string, int>
     */
    private function counts(array $files, array $localFiles, array $remoteFiles): array
    {
        $tally = static fn (string $state): int => count(array_filter(
            $files,
            static fn (array $file): bool => $file['state'] === $state,
        ));

        return [
            'total' => count($files),
            'local' => count($localFiles),
            'gdrive' => count($remoteFiles),
            'synced' => $tally(self::SYNCED),
            'pending_push' => count(array_filter($files, static fn (array $f): bool => $f['can_push'] === true)),
            'local_only' => $tally(self::LOCAL_ONLY),
            'gdrive_only' => $tally(self::GDRIVE_ONLY),
            'local_newer' => $tally(self::LOCAL_NEWER),
            'remote_newer' => $tally(self::GDRIVE_NEWER),
            'different' => $tally(self::DIFFERENT),
            'errors' => $tally(self::COMPARE_ERROR),
        ];
    }

    private function disk(string $name): ?Filesystem
    {
        try {
            return Storage::disk($name);
        } catch (Throwable) {
            return null;
        }
    }
}
