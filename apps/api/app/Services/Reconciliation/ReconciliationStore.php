<?php

namespace App\Services\Reconciliation;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * Local file handling for the reconciliation layer.
 *
 * Two guarantees, and they are the reason this is a class rather than a few
 * calls to Storage:
 *
 * Writes are atomic. A document is written to a temporary path and renamed
 * into place, so a process killed mid-write leaves the previous complete
 * document rather than a truncated one. Reconciliation is the recovery layer;
 * a half-written recovery layer is worse than an old one.
 *
 * Encoding is deterministic. The same logical input produces byte-identical
 * output, which is what makes "has this asset changed?" answerable by
 * comparing hashes instead of by comparing documents. Key order follows
 * insertion order, so the builders decide the shape and this never reorders
 * it; floats use PHP's shortest round-trip representation, which is stable
 * for a given interpreter and is why the schema version travels with the
 * document.
 */
class ReconciliationStore
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('reconciliation.local_disk', 'local'));
    }

    public function root(): string
    {
        return trim((string) config('reconciliation.path', 'reconciliation'), '/');
    }

    public function manifestPath(): string
    {
        return $this->root().'/manifest.json';
    }

    public function assetPath(string $symbol): string
    {
        return $this->root().'/assets/'.strtoupper(trim($symbol)).'.json';
    }

    /**
     * The symbol an asset document path describes, or null if it is not one.
     */
    public function symbolOfPath(string $path): ?string
    {
        $prefix = $this->root().'/assets/';

        if (! str_starts_with($path, $prefix) || ! str_ends_with($path, '.json')) {
            return null;
        }

        $symbol = strtoupper(substr($path, strlen($prefix), -5));

        return preg_match('/^[A-Z0-9._-]{1,32}$/', $symbol) === 1 ? $symbol : null;
    }

    /**
     * Every asset document currently on disk, by symbol.
     *
     * @return array<int, string>
     */
    public function storedSymbols(): array
    {
        $symbols = [];

        foreach ($this->disk()->files($this->root().'/assets') as $path) {
            $symbol = $this->symbolOfPath($path);

            if ($symbol !== null) {
                $symbols[] = $symbol;
            }
        }

        sort($symbols);

        return $symbols;
    }

    /**
     * Encode a document exactly as it will be stored.
     *
     * Public because the fingerprint and the hash have to agree with what is
     * written, and the only way to guarantee that is for all three to use one
     * encoder.
     *
     * @param  array<string, mixed>  $document
     */
    public function encode(array $document): string
    {
        return json_encode($document, self::ENCODE_FLAGS);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException when the payload is not a JSON object
     */
    public function decode(string $contents): array
    {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('The reconciliation document is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * Write a document, atomically, and report what was stored.
     *
     * The rename is what makes it atomic; the read-back is what makes the
     * reported hash a fact about the file rather than about the string we
     * intended to write.
     *
     * @param  array<string, mixed>  $document
     * @return array{path: string, hash: string, size: int, changed: bool}
     */
    public function writeAsset(string $symbol, array $document): array
    {
        $path = $this->assetPath($symbol);
        $contents = $this->encode($document);
        $hash = hash('sha256', $contents);

        // Nothing to do when the bytes already match. This is the second line
        // of defence behind the fingerprint: even if a fingerprint changed
        // spuriously, an identical document is not rewritten, so the mirror
        // is never handed an upload that would change nothing.
        if ($this->hashOf($path) === $hash) {
            return ['path' => $path, 'hash' => $hash, 'size' => strlen($contents), 'changed' => false];
        }

        $this->putAtomically($path, $contents);

        return ['path' => $path, 'hash' => $hash, 'size' => strlen($contents), 'changed' => true];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{path: string, hash: string, size: int, changed: bool}
     */
    public function writeManifest(array $manifest): array
    {
        $path = $this->manifestPath();
        $contents = $this->encode($manifest);
        $hash = hash('sha256', $contents);

        if ($this->hashOf($path) === $hash) {
            return ['path' => $path, 'hash' => $hash, 'size' => strlen($contents), 'changed' => false];
        }

        $this->putAtomically($path, $contents);

        return ['path' => $path, 'hash' => $hash, 'size' => strlen($contents), 'changed' => true];
    }

    /**
     * The stored manifest, or an empty one when there is none yet.
     *
     * A manifest that cannot be parsed is treated as absent rather than
     * fatal: it is an index, it is rebuilt from the documents themselves, and
     * refusing to run because the index is corrupt would block the one
     * operation that would fix it.
     *
     * @return array<string, mixed>
     */
    public function readManifest(): array
    {
        $contents = $this->read($this->manifestPath());

        if ($contents === null) {
            return [];
        }

        try {
            return $this->decode($contents);
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException when the document exists and is not valid JSON
     */
    public function readAsset(string $symbol): ?array
    {
        $contents = $this->read($this->assetPath($symbol));

        return $contents === null ? null : $this->decode($contents);
    }

    public function read(string $path): ?string
    {
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): ?int
    {
        return $this->disk()->exists($path) ? (int) $this->disk()->size($path) : null;
    }

    /**
     * SHA-256 of a stored file, or null when it does not exist.
     */
    public function hashOf(string $path): ?string
    {
        $contents = $this->read($path);

        return $contents === null ? null : hash('sha256', $contents);
    }

    /**
     * Remove an asset document, for a symbol that no longer qualifies.
     */
    public function deleteAsset(string $symbol): bool
    {
        $path = $this->assetPath($symbol);

        return $this->disk()->exists($path) && $this->disk()->delete($path);
    }

    /**
     * Write through a temporary neighbour so readers never see a partial file.
     *
     * The temporary lives in the same directory on purpose: a rename across
     * filesystems is a copy, and a copy is not atomic.
     *
     * The rename goes straight over the destination. It used to delete the
     * destination first, which quietly gave away the guarantee this method
     * exists to provide: between the delete and the move there was no file at
     * all, so a failure or a kill in that window destroyed the previous
     * complete document rather than preserving it. For the manifest that is
     * the worst version of the bug -- the recovery index vanishes locally
     * while cold storage still advertises one, and the dashboard then has to
     * reason about a state that should not be reachable. POSIX rename()
     * replaces an existing file atomically, so the delete bought nothing.
     */
    private function putAtomically(string $path, string $contents): void
    {
        $disk = $this->disk();
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if ($disk->put($temporary, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write the reconciliation temporary file %s.', $temporary));
        }

        try {
            if (! $disk->move($temporary, $path)) {
                throw new RuntimeException(sprintf('Could not move %s into place.', $temporary));
            }
        } catch (\Throwable $exception) {
            // Never leave the scratch file behind to be mistaken for a
            // document later.
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }

            throw $exception;
        }
    }
}
