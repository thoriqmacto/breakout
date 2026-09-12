<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * One writer at a time in the saved browser profile.
 *
 * Chromium holds an exclusive lock on a profile directory: a second launch
 * against the same one fails outright rather than queueing. Two jobs now want
 * that profile -- the hourly token renewal, and the daily index read that
 * needs its session to get past the catalogue's sign-in -- so they have to
 * take turns.
 *
 * The two callers wait for very different lengths on purpose. A token renewal
 * that gives up leaves the collectors without a bearer, so it waits. An index
 * read that gives up costs a day of badge staleness, so it does not.
 */
final class BrowserProfileLock
{
    public const KEY = 'browser:profile';

    /**
     * How long the renewal will wait for the profile before giving up.
     */
    public const RENEWAL_WAIT_SECONDS = 180;

    /**
     * How long a catalogue read will wait. Short: tomorrow will do.
     */
    public const READ_WAIT_SECONDS = 20;

    public static function renewalWait(): int
    {
        return max(0, (int) config('browser_auth.profile_wait_seconds', self::RENEWAL_WAIT_SECONDS));
    }

    public static function readWait(): int
    {
        return max(0, (int) config('market_indexes.browser.profile_wait_seconds', self::READ_WAIT_SECONDS));
    }

    public static function make(int $ttlSeconds = 900): Lock
    {
        return Cache::lock(self::KEY, max(60, $ttlSeconds));
    }

    /**
     * Acquire, waiting up to the given number of seconds.
     *
     * Returns false rather than throwing, because both callers have something
     * better to do than raise an exception: one reports a reason, the other
     * comes back tomorrow.
     */
    public static function acquire(Lock $lock, int $waitSeconds): bool
    {
        if ($waitSeconds <= 0) {
            return $lock->get();
        }

        try {
            $lock->block($waitSeconds);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Release without letting a lost lock become the reported failure.
     *
     * A lock whose TTL expired mid-run cannot be released, and failing the
     * work over that would be worse than the leak it prevents.
     */
    public static function release(?Lock $lock): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (\Throwable) {
            // Intentionally ignored; see above.
        }
    }
}
