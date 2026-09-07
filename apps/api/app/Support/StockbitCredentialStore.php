<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Storage;

/**
 * The portal password, encrypted at rest, so a token can be renewed unattended.
 *
 * This reverses a deliberate decision, and the reversal is worth stating
 * plainly. The token lifecycle was built so that no password ever had to reach
 * this server: renewal was a person pasting a token, and the worst a stolen
 * disk could give up was a bearer that expires within hours. Storing the
 * password removes that expiry. Whoever holds this file and the app key holds
 * the portal account until the password is changed.
 *
 * What that buys is unattended renewal, which is the only way a scheduled job
 * keeps running without someone at a keyboard every few hours. It is opt-in --
 * nothing writes here unless `stockbit:credentials` is run -- and `--forget`
 * is a complete undo.
 *
 * Encrypted with the app key through the same mechanism as the token store, so
 * the file is useless on its own. It is never read back out through the API,
 * never logged, and never passed as a command argument, where `ps` would show
 * it to every user on the box.
 */
class StockbitCredentialStore
{
    private const DISK = 'local';

    private const PATH = 'stockbit_credentials.json';

    /**
     * @return array{username: string, password: string}|null
     */
    public function get(): ?array
    {
        if (! Storage::disk(self::DISK)->exists(self::PATH)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk(self::DISK)->get(self::PATH), true);

        if (! is_array($decoded)) {
            return null;
        }

        try {
            $username = decrypt((string) ($decoded['username_encrypted'] ?? ''));
            $password = decrypt((string) ($decoded['password_encrypted'] ?? ''));
        } catch (DecryptException) {
            // Written under a different APP_KEY. status() reports this as
            // stored-but-unreadable, because behaving as though nothing were
            // stored would send someone looking for a file that is right there.
            return null;
        }

        if (! is_string($username) || ! is_string($password) || $username === '' || $password === '') {
            return null;
        }

        return ['username' => $username, 'password' => $password];
    }

    public function put(string $username, string $password): void
    {
        Storage::disk(self::DISK)->put(self::PATH, (string) json_encode([
            'v' => 1,
            'username_encrypted' => encrypt($username),
            'password_encrypted' => encrypt($password),
            'stored_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function forget(): void
    {
        Storage::disk(self::DISK)->delete(self::PATH);
    }

    public function exists(): bool
    {
        return Storage::disk(self::DISK)->exists(self::PATH);
    }

    /**
     * Enough to tell whether the right account is stored, and no more.
     *
     * @return array{stored: bool, username: ?string, stored_at: ?string, readable: bool}
     */
    public function status(): array
    {
        if (! $this->exists()) {
            return ['stored' => false, 'username' => null, 'stored_at' => null, 'readable' => false];
        }

        $decoded = json_decode((string) Storage::disk(self::DISK)->get(self::PATH), true);
        $credentials = $this->get();

        return [
            'stored' => true,
            'username' => $credentials['username'] ?? null,
            'stored_at' => is_array($decoded) ? ($decoded['stored_at'] ?? null) : null,
            // False means the file is there but this APP_KEY cannot open it.
            'readable' => $credentials !== null,
        ];
    }
}
