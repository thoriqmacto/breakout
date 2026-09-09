<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * When the current Google Drive grant was first seen working.
 *
 * A refresh token carries no issue date and no expiry, so an age can only be
 * observed, never read: the first time a probe finds a given token working,
 * that moment is recorded, and the clock runs from there. It is a lower bound
 * -- the grant may have been issued earlier -- which is the safe direction for
 * a warning, since it makes the estimate too early rather than too late.
 *
 * A new token resets it. Identity is a fingerprint, never the token: four
 * characters of a SHA-256, which is enough to notice a change and useless to
 * anyone who reads the file.
 */
class GoogleDriveGrantLog
{
    private const DISK = 'local';

    private const PATH = 'google-drive/grant.json';

    /**
     * Note that this grant was working, and report when it first was.
     *
     * Writes only when the fingerprint changes, so the recorded moment stays
     * the first sighting rather than creeping forward to the latest one.
     */
    public function observe(string $refreshToken, ?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now();
        $fingerprint = $this->fingerprint($refreshToken);
        $stored = $this->read();

        if (($stored['fingerprint'] ?? null) === $fingerprint) {
            $seen = $this->parse($stored['first_seen_at'] ?? null);

            if ($seen !== null) {
                return $seen;
            }
        }

        $this->write(['fingerprint' => $fingerprint, 'first_seen_at' => $now->toIso8601String()]);

        return $now;
    }

    /**
     * How long the current grant has been known to work, in whole days.
     *
     * Null when this token has not been seen before, which is not zero: a
     * grant with no history is one nothing can be said about yet.
     */
    public function ageInDays(string $refreshToken, ?Carbon $now = null): ?int
    {
        $stored = $this->read();

        if (($stored['fingerprint'] ?? null) !== $this->fingerprint($refreshToken)) {
            return null;
        }

        $seen = $this->parse($stored['first_seen_at'] ?? null);

        return $seen === null ? null : (int) $seen->diffInDays($now ?? Carbon::now());
    }

    public function fingerprint(string $refreshToken): string
    {
        return substr(hash('sha256', trim($refreshToken)), -4);
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
            // An unreadable record is an absent one. It is an observation
            // about a token, not the token itself, and refusing to run
            // because it cannot be parsed would break the check over
            // something it can simply rewrite.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(array $record): void
    {
        Storage::disk(self::DISK)->put(self::PATH, (string) json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
