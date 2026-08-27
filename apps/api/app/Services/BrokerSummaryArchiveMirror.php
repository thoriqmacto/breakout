<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mirrors the broker-summary JSON archive to cold storage.
 *
 * The OHLCV seed CSVs have had a mirror for a while (BarCsvMirror); the JSON
 * archive that the market-detector scrape writes has not, so the only copy of
 * a weekly broker-summary window lived on one disk. This is that missing path,
 * built with the same guarantees:
 *
 *  - the relative path under stockbit.save_dir is preserved exactly, so the
 *    remote layout matches the local one and the importer's filename-derived
 *    metadata still reads correctly after a restore;
 *  - content, not names, decides whether an upload is needed, so re-running a
 *    weekly job does not re-send a file that has not changed;
 *  - a Drive failure is reported, never swallowed, and never silently retried
 *    into a success;
 *  - the local file is the working copy and is never deleted, moved or
 *    truncated -- a successful upload changes nothing locally, and a failed
 *    one leaves the only good copy exactly where it was.
 */
class BrokerSummaryArchiveMirror
{
    /** Uploaded because the remote copy was absent or differed. */
    public const UPLOADED = 'uploaded';

    /** The remote copy already held identical bytes. */
    public const SKIPPED_UNCHANGED = 'skipped_unchanged';

    /** The transfer was attempted and did not succeed. */
    public const FAILED = 'failed';

    private const MAX_ATTEMPTS = 4;

    private const RETRY_BASE_MICROSECONDS = 500_000;

    /**
     * Fragments of Drive/S3 throttling and transient faults worth retrying.
     */
    private const RETRYABLE = [
        'ratelimitexceeded', 'userratelimitexceeded', 'quotaexceeded', 'backenderror',
        'internalerror', 'try again', 'timed out', 'timeout',
        '403', '429', '500', '502', '503', '504',
    ];

    /**
     * @var (callable(int): void)|null
     */
    private $sleeper = null;

    /**
     * Whether a mirror disk is configured at all.
     */
    public function enabled(?string $disk = null): bool
    {
        return $this->resolveDisk($disk) !== null;
    }

    /**
     * Resolve the cold-storage disk, falling back to configuration. Null means
     * mirroring is switched off, which is the default for local development.
     */
    public function resolveDisk(?string $disk = null): ?string
    {
        $disk = $disk !== null ? trim($disk) : '';

        if ($disk === '') {
            $configured = config('automation.broker_summary_mirror_disk');
            $disk = is_string($configured) ? trim($configured) : '';
        }

        return $disk !== '' ? $disk : null;
    }

    /**
     * Mirror an explicit set of archive paths.
     *
     * Paths are the ones the scrape just wrote, relative to the local disk
     * root (e.g. "broker_summary/BBCA_2026-08-24_2026-08-28_TYPE.json"), so a
     * weekly run uploads its own handful of files rather than walking the
     * whole archive.
     *
     * @param  array<int, string>  $paths
     * @return array{
     *     disk: ?string,
     *     uploaded: array<int, string>,
     *     skipped_unchanged: array<int, string>,
     *     missing: array<int, string>,
     *     failed: array<int, array{path: string, message: string}>
     * }
     */
    public function mirror(array $paths, ?string $disk = null): array
    {
        $result = [
            'disk' => null,
            'uploaded' => [],
            'skipped_unchanged' => [],
            'missing' => [],
            'failed' => [],
        ];

        $disk = $this->resolveDisk($disk);

        if ($disk === null) {
            return $result;
        }

        $result['disk'] = $disk;

        $remote = $this->filesystem($disk);

        if ($remote === null) {
            foreach ($this->normalize($paths) as $path) {
                $result['failed'][] = [
                    'path' => $path,
                    'message' => sprintf('The [%s] disk could not be resolved.', $disk),
                ];
            }

            return $result;
        }

        $local = Storage::disk((string) config('stockbit.save_disk', 'local'));

        foreach ($this->normalize($paths) as $path) {
            try {
                if (! $local->exists($path)) {
                    $result['missing'][] = $path;

                    continue;
                }

                $contents = (string) $local->get($path);

                if ($this->remoteMatches($remote, $path, $contents)) {
                    $result['skipped_unchanged'][] = $path;

                    continue;
                }

                $this->attempt(fn () => $remote->put($path, $contents));

                // Verify rather than trust the absence of an exception: a
                // silently truncated upload is exactly the failure a cold
                // storage copy exists to survive.
                if (! $this->remoteMatches($remote, $path, $contents)) {
                    $result['failed'][] = [
                        'path' => $path,
                        'message' => 'The upload completed but the remote copy does not match the local file.',
                    ];

                    continue;
                }

                $result['uploaded'][] = $path;
            } catch (Throwable $exception) {
                // The local JSON is untouched and remains the source of truth.
                $result['failed'][] = ['path' => $path, 'message' => $exception->getMessage()];

                Log::warning('Broker summary archive mirror failed.', [
                    'path' => $path,
                    'disk' => $disk,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Mirror the whole archive, optionally only files modified on or after a
     * date. Used by the manual push command and by generic post-run cold
     * storage sync.
     *
     * @return array{
     *     disk: ?string,
     *     uploaded: array<int, string>,
     *     skipped_unchanged: array<int, string>,
     *     missing: array<int, string>,
     *     failed: array<int, array{path: string, message: string}>
     * }
     */
    public function mirrorAll(?string $disk = null, ?string $since = null): array
    {
        return $this->mirror($this->localPaths($since), $disk);
    }

    /**
     * Archive files on the local disk, newest-modified first when filtered.
     *
     * @return array<int, string>
     */
    public function localPaths(?string $since = null): array
    {
        $local = Storage::disk((string) config('stockbit.save_disk', 'local'));
        $directory = trim((string) config('stockbit.save_dir', 'broker_summary'), '/');

        try {
            $paths = $local->files($directory);
        } catch (Throwable $exception) {
            Log::warning('Broker summary archive could not be listed.', ['message' => $exception->getMessage()]);

            return [];
        }

        $paths = array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_ends_with(strtolower($path), '.json'),
        ));

        if ($since === null) {
            sort($paths);

            return $paths;
        }

        $threshold = strtotime($since.' 00:00:00');

        if ($threshold === false) {
            sort($paths);

            return $paths;
        }

        $filtered = [];

        foreach ($paths as $path) {
            try {
                if ($local->lastModified($path) >= $threshold) {
                    $filtered[] = $path;
                }
            } catch (Throwable) {
                // Without a timestamp, include it: an unnecessary content
                // comparison is cheaper than a file that never gets backed up.
                $filtered[] = $path;
            }
        }

        sort($filtered);

        return $filtered;
    }

    /**
     * Condense a mirror result into the shape stored on a run and rendered in
     * the dashboard.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function summarize(array $result): array
    {
        return [
            'disk' => $result['disk'] ?? null,
            'uploaded' => count($result['uploaded'] ?? []),
            'skipped_unchanged' => count($result['skipped_unchanged'] ?? []),
            'missing' => count($result['missing'] ?? []),
            'failed' => array_map(
                static fn (array $failure): string => ($failure['path'] ?? '?').': '.($failure['message'] ?? 'unknown error'),
                $result['failed'] ?? [],
            ),
            'status' => ($result['failed'] ?? []) === [] ? 'ok' : 'failed',
        ];
    }

    /**
     * Override the retry sleep. Intended for tests.
     *
     * @param  (callable(int): void)|null  $sleeper
     */
    public function setSleeper(?callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    /**
     * Whether the remote already holds these exact bytes.
     *
     * Compared on content, because a broker-summary filename encodes the
     * symbol and range and therefore stays the same when the payload is
     * re-fetched and changes.
     */
    private function remoteMatches(Filesystem $remote, string $path, string $contents): bool
    {
        try {
            if (! $this->attempt(fn () => $remote->fileExists($path))) {
                return false;
            }

            $remoteContents = $this->attempt(fn () => $remote->get($path));
        } catch (Throwable) {
            // Unable to read the remote copy: treat it as not matching, so the
            // upload is attempted rather than skipped on a guess.
            return false;
        }

        return is_string($remoteContents) && hash('sha256', $remoteContents) === hash('sha256', $contents);
    }

    private function filesystem(string $disk): ?Filesystem
    {
        try {
            return Storage::disk($disk);
        } catch (Throwable $exception) {
            Log::warning('Broker summary archive mirror disk unavailable.', [
                'disk' => $disk,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $operation
     * @return TValue
     */
    private function attempt(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                $attempt++;

                if ($attempt >= self::MAX_ATTEMPTS || ! $this->isRetryable($exception)) {
                    throw $exception;
                }

                $this->sleep(self::RETRY_BASE_MICROSECONDS * (2 ** ($attempt - 1)));
            }
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        $haystack = strtolower($exception->getMessage());
        $code = $exception->getCode();

        if (is_int($code) && $code > 0) {
            $haystack .= ' '.$code;
        }

        foreach (self::RETRYABLE as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);

            return;
        }

        usleep($microseconds);
    }

    /**
     * Keep every path inside the configured archive directory.
     *
     * Paths reach this service from a scrape it just ran, not from a browser,
     * but a mirror that will happily upload "../../.env" because a caller
     * passed one is a mirror waiting to be misused.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function normalize(array $paths): array
    {
        $directory = trim((string) config('stockbit.save_dir', 'broker_summary'), '/');
        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = ltrim(trim($path), '/');

            if ($path === '' || str_contains($path, '..')) {
                continue;
            }

            if ($directory !== '' && ! str_starts_with($path, $directory.'/')) {
                continue;
            }

            $normalized[$path] = $path;
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }
}
