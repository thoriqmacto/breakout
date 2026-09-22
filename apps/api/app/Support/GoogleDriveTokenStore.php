<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Where the Google Drive refresh token lives once a person has granted it.
 *
 * The token used to be GOOGLE_DRIVE_REFRESH_TOKEN, minted by hand in the OAuth
 * Playground and pasted into .env. That works exactly once and then has to be
 * redone by whoever still remembers the procedure, on a box where editing .env
 * means a deploy step -- which is why the renewal instructions on the backups
 * page ran to nine steps.
 *
 * Written here instead, a grant obtained through the consent screen takes
 * effect immediately and survives a deploy, which `git reset --hard` would not
 * allow for anything version controlled.
 *
 * Encrypted at rest with the application key, the same way StockbitTokenStore
 * holds the Stockbit bearer. This is a credential with no expiry that can read
 * and write the whole of a personal Drive, so it is never logged, never
 * returned by the API, and identified in the UI only by a fingerprint.
 *
 * The environment variable is still honoured when the store is empty. An
 * installation that has not connected through the button yet keeps working on
 * the token it already has, and only stops using it once a newer grant
 * replaces it.
 */
class GoogleDriveTokenStore
{
    private const DISK = 'local';

    private const PATH = 'google-drive/token.json';

    /** Where the token in use came from. */
    public const SOURCE_STORE = 'store';

    public const SOURCE_ENV = 'env';

    public const SOURCE_NONE = 'none';

    /**
     * The refresh token to authenticate with, stored grant first.
     */
    public function get(): ?string
    {
        return $this->stored() ?? $this->fromEnvironment();
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Which of the two supplied the token, for the status card.
     *
     * "env" is worth naming rather than hiding: it tells an operator that the
     * connect button has not been used here yet, so the pasted token is still
     * the thing keeping backups alive.
     */
    public function source(): string
    {
        if ($this->stored() !== null) {
            return self::SOURCE_STORE;
        }

        return $this->fromEnvironment() !== null ? self::SOURCE_ENV : self::SOURCE_NONE;
    }

    /**
     * Replace the stored grant.
     *
     * `connected_at` is recorded here because it is the one moment that can be
     * known exactly: a refresh token carries no issue date, so every other
     * estimate of its age is an observation after the fact.
     */
    public function put(string $refreshToken, ?string $account = null, ?Carbon $now = null): void
    {
        $refreshToken = trim($refreshToken);

        if ($refreshToken === '') {
            return;
        }

        $this->write([
            'v' => 1,
            'refresh_token_encrypted' => Crypt::encryptString($refreshToken),
            'account' => $account,
            'connected_at' => ($now ?? Carbon::now())->toIso8601String(),
        ]);
    }

    public function forget(): void
    {
        Storage::disk(self::DISK)->delete(self::PATH);
    }

    /** When the stored grant was connected, or null when it came from .env. */
    public function connectedAt(): ?Carbon
    {
        $record = $this->read();

        if (! isset($record['connected_at']) || ! is_string($record['connected_at'])) {
            return null;
        }

        try {
            return Carbon::parse($record['connected_at']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The Google account that granted it, when the consent flow reported one. */
    public function account(): ?string
    {
        $record = $this->read();
        $account = $record['account'] ?? null;

        return is_string($account) && $account !== '' ? $account : null;
    }

    /**
     * Four characters of a SHA-256, enough to tell two grants apart.
     *
     * Same shape as GoogleDriveGrantLog's fingerprint so the two agree about
     * which token they are describing.
     */
    public function fingerprint(?string $refreshToken = null): ?string
    {
        $refreshToken ??= $this->get();

        return $refreshToken === null ? null : substr(hash('sha256', trim($refreshToken)), -4);
    }

    private function stored(): ?string
    {
        $record = $this->read();
        $encrypted = $record['refresh_token_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Written under a different APP_KEY. Treated as absent so the
            // environment fallback can still serve, rather than failing every
            // Drive operation on a record nothing can read.
            return null;
        }

        return trim($token) === '' ? null : trim($token);
    }

    private function fromEnvironment(): ?string
    {
        $token = trim((string) config('filesystems.disks.gdrive.refreshToken', ''));

        return $token === '' ? null : $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists(self::PATH)) {
            return [];
        }

        try {
            $decoded = json_decode((string) $disk->get(self::PATH), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(array $record): void
    {
        Storage::disk(self::DISK)->put(
            self::PATH,
            (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
