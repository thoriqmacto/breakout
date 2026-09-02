<?php

namespace App\Services\Reconciliation;

use App\Services\ContentHasher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mirrors the reconciliation layer to cold storage, with commit ordering.
 *
 * Drive is durable storage, not a database, so the only transactional
 * primitive available is the order in which files are written. The manifest is
 * that primitive: it names every asset document and its hash, so a reader
 * trusts it completely -- which means it must never be published ahead of the
 * documents it describes.
 *
 *   1. upload every changed asset document
 *   2. verify each one by reading it back
 *   3. only if all of them succeeded, upload the manifest
 *
 * A single failed asset upload leaves the previous manifest in place. The
 * remote state is then *old*, which is recoverable, rather than *inconsistent*,
 * which is not: a manifest promising a hash that no remote document has would
 * send a disaster recovery down a path that cannot complete, at the exact
 * moment there is nothing else to fall back to.
 *
 * Verification is a read-back, not the absence of an exception. The archive
 * mirror already learned that lesson -- an upload can return cleanly and land
 * truncated -- and weakening it here would put the weakest check on the most
 * important files.
 */
class ReconciliationMirror
{
    public const UPLOADED = 'uploaded';

    public const SKIPPED_UNCHANGED = 'skipped_unchanged';

    public const FAILED = 'failed';

    private const MAX_ATTEMPTS = 4;

    private const RETRY_BASE_MICROSECONDS = 500_000;

    /** @var (callable(int): void)|null */
    private $sleeper = null;

    public function __construct(
        private readonly ReconciliationStore $store,
        private readonly ContentHasher $hasher,
    ) {}

    public function enabled(?string $disk = null): bool
    {
        return $this->resolveDisk($disk) !== null;
    }

    public function resolveDisk(?string $disk = null): ?string
    {
        $name = $disk ?: config('reconciliation.mirror_disk');

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * Push the named asset documents, then the manifest.
     *
     * @param  array<int, string>  $symbols  documents to consider; empty means every stored document
     * @return array<string, mixed>
     */
    public function push(array $symbols = [], ?string $disk = null): array
    {
        $diskName = $this->resolveDisk($disk);

        $result = [
            'enabled' => $diskName !== null,
            'disk' => $diskName,
            'assets' => ['uploaded' => [], 'skipped' => [], 'failed' => []],
            'manifest' => ['status' => 'skipped', 'reason' => null, 'hash' => null],
            'degraded' => false,
        ];

        if ($diskName === null) {
            $result['manifest']['reason'] = 'No reconciliation mirror disk is configured.';

            return $result;
        }

        $remote = $this->filesystem($diskName);

        if ($remote === null) {
            $result['manifest']['reason'] = sprintf('The "%s" disk could not be resolved.', $diskName);
            $result['degraded'] = true;

            return $result;
        }

        $symbols = $symbols === [] ? $this->store->storedSymbols() : $this->normalize($symbols);

        foreach ($symbols as $symbol) {
            $path = $this->store->assetPath($symbol);
            $contents = $this->store->read($path);

            if ($contents === null) {
                $result['assets']['failed'][$symbol] = 'The local reconciliation document is missing.';

                continue;
            }

            try {
                $status = $this->upload($remote, $path, $contents);

                if ($status === self::SKIPPED_UNCHANGED) {
                    $result['assets']['skipped'][] = $symbol;
                } else {
                    $result['assets']['uploaded'][] = $symbol;
                }
            } catch (Throwable $exception) {
                $result['assets']['failed'][$symbol] = $exception->getMessage();

                Log::warning('Reconciliation asset mirror failed.', [
                    'symbol' => $symbol,
                    'disk' => $diskName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        sort($result['assets']['uploaded']);
        sort($result['assets']['skipped']);

        // The commit point. Anything short of every document being present
        // and verified remotely leaves the old manifest standing.
        if ($result['assets']['failed'] !== []) {
            $result['degraded'] = true;
            $result['manifest']['status'] = 'withheld';
            $result['manifest']['reason'] = sprintf(
                '%d asset document(s) failed to upload, so the remote manifest was left describing the previous complete set.',
                count($result['assets']['failed']),
            );

            return $result;
        }

        $manifestPath = $this->store->manifestPath();
        $manifest = $this->store->read($manifestPath);

        if ($manifest === null) {
            $result['manifest']['reason'] = 'There is no local manifest to publish.';
            $result['degraded'] = true;

            return $result;
        }

        try {
            $status = $this->upload($remote, $manifestPath, $manifest);

            $result['manifest']['status'] = $status === self::SKIPPED_UNCHANGED ? 'unchanged' : 'published';
            $result['manifest']['hash'] = hash('sha256', $manifest);
        } catch (Throwable $exception) {
            $result['manifest']['status'] = self::FAILED;
            $result['manifest']['reason'] = $exception->getMessage();
            $result['degraded'] = true;

            Log::warning('Reconciliation manifest mirror failed.', [
                'disk' => $diskName,
                'error' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * What the remote layer looks like, without downloading the documents.
     *
     * Used by the fast dashboard path: the remote manifest's hash against the
     * local one answers "is my recovery copy current?" in two reads, whatever
     * the asset count.
     *
     * @return array<string, mixed>
     */
    public function remoteState(?string $disk = null): array
    {
        $diskName = $this->resolveDisk($disk);

        $state = [
            'enabled' => $diskName !== null,
            'disk' => $diskName,
            'reachable' => false,
            'manifest_present' => false,
            'manifest_hash' => null,
            'local_manifest_hash' => $this->store->hashOf($this->store->manifestPath()),
            'in_sync' => false,
            'message' => null,
        ];

        if ($diskName === null) {
            $state['message'] = 'No reconciliation mirror disk is configured.';

            return $state;
        }

        $remote = $this->filesystem($diskName);

        if ($remote === null) {
            $state['message'] = sprintf('The "%s" disk could not be resolved.', $diskName);

            return $state;
        }

        try {
            $path = $this->store->manifestPath();

            if (! $remote->exists($path)) {
                $state['reachable'] = true;
                $state['message'] = 'No reconciliation manifest has been published yet.';

                return $state;
            }

            $contents = $remote->get($path);
            $state['reachable'] = true;
            $state['manifest_present'] = true;
            $state['manifest_hash'] = is_string($contents) ? hash('sha256', $contents) : null;
            // Only ever true when two hashes were actually compared. An
            // unreachable remote is unknown, and unknown is not synchronised.
            $state['in_sync'] = $state['manifest_hash'] !== null
                && $state['manifest_hash'] === $state['local_manifest_hash'];
        } catch (Throwable $exception) {
            $state['message'] = $exception->getMessage();
        }

        return $state;
    }

    public function setSleeper(?callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    /**
     * Upload and prove it landed.
     *
     * @throws \RuntimeException when the remote copy does not match after the upload
     */
    private function upload(Filesystem $remote, string $path, string $contents): string
    {
        if ($this->remoteMatches($remote, $path, $contents)) {
            return self::SKIPPED_UNCHANGED;
        }

        $this->attempt(static fn () => $remote->put($path, $contents));

        if (! $this->remoteMatches($remote, $path, $contents)) {
            throw new \RuntimeException(sprintf(
                'The upload of %s reported success but the remote copy does not match.',
                $path,
            ));
        }

        return self::UPLOADED;
    }

    /**
     * Whether the remote already holds these exact bytes.
     *
     * Compared through ContentHasher, which answers in MD5 -- deliberately,
     * because that is the checksum Drive already stores and will hand over in
     * the file's metadata. Asking for it costs one metadata call instead of a
     * full download, and these documents carry every bar an asset has, so the
     * difference is the whole cost of the verification step.
     *
     * MD5 is doing content comparison here, not authentication: both sides
     * are our own files and the question is only whether the upload landed
     * intact. The documents' integrity guarantee is the SHA-256 recorded in
     * the manifest, which is what a restore checks.
     */
    private function remoteMatches(Filesystem $remote, string $path, string $contents): bool
    {
        try {
            if (! $remote->exists($path)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $actual = $this->hasher->remote($remote, $path);

        // A checksum that could not be obtained is not a match. Treating
        // "unknown" as "fine" is exactly how an upload that silently
        // truncated would be reported as verified.
        return $actual !== null && hash_equals(md5($contents), $actual);
    }

    private function attempt(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (Throwable $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                $this->sleep(self::RETRY_BASE_MICROSECONDS * (2 ** ($attempt - 1)));
            }
        }
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);

            return;
        }

        usleep($microseconds);
    }

    private function filesystem(string $disk): ?Filesystem
    {
        try {
            return Storage::disk($disk);
        } catch (Throwable $exception) {
            Log::warning('Reconciliation mirror disk unavailable.', [
                'disk' => $disk,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<int, string>
     */
    private function normalize(array $symbols): array
    {
        $out = [];

        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));

            if ($symbol !== '' && preg_match('/^[A-Z0-9._-]{1,32}$/', $symbol) === 1) {
                $out[$symbol] = $symbol;
            }
        }

        $out = array_values($out);
        sort($out);

        return $out;
    }
}
