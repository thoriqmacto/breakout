<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mirrors the OHLCV seed CSVs between local disk and a durable remote disk
 * (Google Drive, S3, ...).
 *
 * The seed CSVs are read-merge-write in a per-symbol, per-date-chunk loop.
 * Driving that loop straight against Drive would cost an API call per
 * iteration and hit `403 rateLimitExceeded`, and Drive offers neither random
 * access nor the atomic rename CsvBars relies on. So CsvBars keeps writing to
 * local disk untouched, and this service batches the transfer around it:
 *
 *   hydrate() -> run the existing local CSV logic -> flush()
 *
 * A null mirror disk disables both halves, which is the default and leaves
 * behaviour identical to a pure-local run.
 */
class BarCsvMirror
{
    /**
     * Attempts made for a single remote transfer before giving up.
     */
    private const MAX_ATTEMPTS = 4;

    /**
     * Base backoff in microseconds, doubled per retry (0.5s, 1s, 2s).
     */
    private const RETRY_BASE_MICROSECONDS = 500_000;

    /**
     * Fragments of Drive/S3 throttling errors worth retrying.
     */
    private const RETRYABLE = [
        'ratelimitexceeded',
        'userratelimitexceeded',
        'quotaexceeded',
        'backenderror',
        'internalerror',
        'try again',
        'timed out',
        'timeout',
        '403',
        '429',
        '500',
        '502',
        '503',
        '504',
    ];

    /**
     * Hashes of the CSVs last transferred, so unchanged files are not re-sent.
     * Persisted locally; the mirror itself is never read to answer this.
     *
     * @var array<string, string>|null
     */
    private ?array $manifest = null;

    /**
     * Sleep hook, overridable so tests do not wait out the backoff.
     *
     * @var (callable(int): void)|null
     */
    private $sleeper = null;

    /**
     * Download seed CSVs from the mirror disk when the local copy is missing
     * or older than the remote one.
     *
     * @param  array<int, string>  $symbols  Symbols to hydrate; empty hydrates everything on the mirror.
     * @param  string|null  $disk  Mirror disk override; null falls back to config('csv.mirror_disk').
     * @return array{disk: ?string, hydrated: array<int, string>, skipped: array<int, string>, failed: array<int, string>}
     */
    public function hydrate(array $symbols = [], ?string $disk = null): array
    {
        $result = ['disk' => null, 'hydrated' => [], 'skipped' => [], 'failed' => []];

        $disk = $this->resolveDisk($disk);

        if ($disk === null) {
            return $result;
        }

        $result['disk'] = $disk;

        $filesystem = $this->filesystem($disk);

        if ($filesystem === null) {
            return $result;
        }

        $symbols = $this->normalizeSymbols($symbols) ?: $this->remoteSymbols($filesystem);

        foreach ($symbols as $symbol) {
            $localPath = $this->localPath($symbol);
            $remotePath = $this->remotePath($symbol);

            try {
                if (! $this->attempt(fn () => $filesystem->fileExists($remotePath))) {
                    $result['skipped'][] = $symbol;

                    continue;
                }

                if (! $this->remoteIsNewer($filesystem, $remotePath, $localPath)) {
                    $result['skipped'][] = $symbol;

                    continue;
                }

                $contents = $this->attempt(fn () => $filesystem->get($remotePath));

                if ($contents === null) {
                    $result['skipped'][] = $symbol;

                    continue;
                }

                $this->writeLocal($localPath, $contents);
                $this->rememberHash($symbol, md5($contents));

                $result['hydrated'][] = $symbol;
            } catch (Throwable $exception) {
                $result['failed'][] = $symbol;
                $this->report('hydrate', $symbol, $disk, $exception);
            }
        }

        $this->saveManifest();

        return $result;
    }

    /**
     * Upload seed CSVs that changed locally to the mirror disk.
     *
     * @param  array<int, string>  $symbols  Symbols to flush; empty flushes every local seed CSV.
     * @param  string|null  $disk  Mirror disk override; null falls back to config('csv.mirror_disk').
     * @return array{disk: ?string, uploaded: array<int, string>, skipped: array<int, string>, failed: array<int, string>}
     */
    public function flush(array $symbols = [], ?string $disk = null): array
    {
        $result = ['disk' => null, 'uploaded' => [], 'skipped' => [], 'failed' => []];

        $disk = $this->resolveDisk($disk);

        if ($disk === null) {
            return $result;
        }

        $result['disk'] = $disk;

        $filesystem = $this->filesystem($disk);

        if ($filesystem === null) {
            return $result;
        }

        $symbols = $this->normalizeSymbols($symbols) ?: $this->localSymbols();

        foreach ($symbols as $symbol) {
            $localPath = $this->localPath($symbol);

            if (! is_file($localPath)) {
                $result['skipped'][] = $symbol;

                continue;
            }

            try {
                $contents = file_get_contents($localPath);

                if ($contents === false) {
                    $result['skipped'][] = $symbol;

                    continue;
                }

                $hash = md5($contents);

                if ($this->rememberedHash($symbol) === $hash) {
                    $result['skipped'][] = $symbol;

                    continue;
                }

                $this->attempt(fn () => $filesystem->put($this->remotePath($symbol), $contents));
                $this->rememberHash($symbol, $hash);

                $result['uploaded'][] = $symbol;
            } catch (Throwable $exception) {
                $result['failed'][] = $symbol;
                $this->report('flush', $symbol, $disk, $exception);
            }
        }

        $this->saveManifest();

        return $result;
    }

    /**
     * Whether mirroring is active for the given override.
     */
    public function enabled(?string $disk = null): bool
    {
        return $this->resolveDisk($disk) !== null;
    }

    /**
     * Resolve the mirror disk name, falling back to configuration.
     *
     * Returns null when mirroring is disabled, which is the default.
     */
    public function resolveDisk(?string $disk = null): ?string
    {
        $disk = $disk !== null ? trim($disk) : '';

        if ($disk === '') {
            $configured = config('csv.mirror_disk');
            $disk = is_string($configured) ? trim($configured) : '';
        }

        return $disk !== '' ? $disk : null;
    }

    /**
     * Symbols currently present on the mirror disk.
     *
     * @return array<int, string>
     */
    public function remoteSymbols(?Filesystem $filesystem = null, ?string $disk = null): array
    {
        $filesystem ??= $this->filesystem($this->resolveDisk($disk) ?? '');

        if ($filesystem === null) {
            return [];
        }

        try {
            $files = $this->attempt(fn () => $filesystem->files($this->remoteDirectory())) ?? [];
        } catch (Throwable $exception) {
            $this->report('list', '*', $disk ?? '', $exception);

            return [];
        }

        return $this->symbolsFromPaths($files);
    }

    /**
     * Symbols currently present in the local seed directory.
     *
     * @return array<int, string>
     */
    public function localSymbols(): array
    {
        return $this->symbolsFromPaths(glob($this->seedDirectory().'/*.csv') ?: []);
    }

    /**
     * Absolute path of a symbol's local seed CSV.
     */
    public function localPath(string $symbol): string
    {
        return $this->seedDirectory().'/'.strtoupper(trim($symbol)).'.csv';
    }

    /**
     * Path of a symbol's CSV on the mirror disk.
     */
    public function remotePath(string $symbol): string
    {
        return $this->remoteDirectory().'/'.strtoupper(trim($symbol)).'.csv';
    }

    /**
     * Override the sleep used between retries. Intended for tests.
     *
     * @param  (callable(int): void)|null  $sleeper
     */
    public function setSleeper(?callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    /**
     * Resolve the mirror filesystem, returning null when it cannot be built.
     *
     * A misconfigured or unreachable mirror must never take down a run whose
     * real work (DB upserts, local CSVs) has already succeeded.
     */
    private function filesystem(string $disk): ?Filesystem
    {
        if ($disk === '') {
            return null;
        }

        try {
            return Storage::disk($disk);
        } catch (Throwable $exception) {
            $this->report('resolve', '*', $disk, $exception);

            return null;
        }
    }

    /**
     * Run a remote operation, retrying throttling and transient errors with
     * exponential backoff.
     *
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

    /**
     * Whether an exception looks like Drive/S3 throttling or a transient fault.
     */
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
     * Whether the remote copy is worth downloading: the local file is absent,
     * or the remote was modified more recently.
     */
    private function remoteIsNewer(Filesystem $filesystem, string $remotePath, string $localPath): bool
    {
        if (! is_file($localPath)) {
            return true;
        }

        try {
            $remoteTime = $this->attempt(fn () => $filesystem->lastModified($remotePath));
        } catch (Throwable) {
            // Without a usable remote timestamp, prefer the local copy: the
            // run is about to extend it, and flush() will push it back.
            return false;
        }

        if (! is_int($remoteTime) || $remoteTime <= 0) {
            return false;
        }

        return $remoteTime > (int) filemtime($localPath);
    }

    /**
     * Write hydrated contents to local disk atomically, matching how CsvBars
     * publishes a file so a partial download can never be read as a CSV.
     */
    private function writeLocal(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $temp = $path.'.tmp';
        file_put_contents($temp, $contents);
        rename($temp, $path);
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<int, string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];

        foreach ($symbols as $symbol) {
            if (! is_string($symbol)) {
                continue;
            }

            $symbol = strtoupper(trim($symbol));

            if ($symbol !== '') {
                $normalized[$symbol] = $symbol;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function symbolsFromPaths(array $paths): array
    {
        $symbols = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! str_ends_with(strtolower($path), '.csv')) {
                continue;
            }

            $symbols[] = pathinfo($path, PATHINFO_FILENAME);
        }

        return $this->normalizeSymbols($symbols);
    }

    private function seedDirectory(): string
    {
        return rtrim((string) config('csv.seed_dir'), '/'.DIRECTORY_SEPARATOR);
    }

    private function remoteDirectory(): string
    {
        return trim((string) config('csv.mirror_path', 'seeds/historical'), '/');
    }

    private function manifestPath(): string
    {
        return storage_path('app/bar-csv-mirror.json');
    }

    /**
     * @return array<string, string>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->manifestPath();

        if (! is_file($path)) {
            return $this->manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->manifest = is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function rememberedHash(string $symbol): ?string
    {
        return $this->manifest()[$this->manifestKey($symbol)] ?? null;
    }

    private function rememberHash(string $symbol, string $hash): void
    {
        $manifest = $this->manifest();
        $manifest[$this->manifestKey($symbol)] = $hash;

        $this->manifest = $manifest;
    }

    /**
     * Manifest entries are scoped per mirror path so pointing the mirror at a
     * different destination re-uploads rather than assuming it is in sync.
     */
    private function manifestKey(string $symbol): string
    {
        return $this->remoteDirectory().'/'.strtoupper(trim($symbol));
    }

    private function saveManifest(): void
    {
        if ($this->manifest === null) {
            return;
        }

        $path = $this->manifestPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        ksort($this->manifest);

        file_put_contents($path, json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function report(string $operation, string $symbol, string $disk, Throwable $exception): void
    {
        Log::warning('Seed CSV mirror failed.', [
            'operation' => $operation,
            'symbol' => $symbol,
            'disk' => $disk,
            'message' => $exception->getMessage(),
        ]);
    }
}
