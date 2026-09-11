<?php

namespace App\Support;

/**
 * Who owns a path, and who is asking.
 *
 * Three places had grown their own copy of this: the reconciliation store,
 * the browser profile check, and now the CSV writer. Each needs the same two
 * facts and each was juggling `posix_getpwuid` with its own fallbacks.
 *
 * Only the lookup is shared. The sentence stays with the caller, because the
 * useful advice differs: a Chromium profile belongs to one Unix user and the
 * fix is to run as them, while a data directory is meant to be shared and the
 * fix is usually ownership. A single generic message would say neither.
 *
 * Every lookup is silenced and falls back, because this code runs while
 * something has already gone wrong -- a diagnosis that throws is worse than
 * one that says "unknown".
 */
final class PathOwnership
{
    /** The owning user's name, its numeric uid if unresolvable, or null. */
    public static function owner(string $path): ?string
    {
        $uid = @fileowner($path);

        if ($uid === false) {
            return null;
        }

        if (function_exists('posix_getpwuid')) {
            $entry = @posix_getpwuid($uid);

            if (is_array($entry) && isset($entry['name'])) {
                return (string) $entry['name'];
            }
        }

        return (string) $uid;
    }

    /** The effective user of this process. */
    public static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = @posix_getpwuid(posix_geteuid());

            if (is_array($entry) && isset($entry['name'])) {
                return (string) $entry['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    /**
     * Why a write into this directory would fail, said plainly.
     *
     * "Absent" and "present but refused" need different fixes, and the error
     * PHP raises -- "Failed to open stream: Permission denied" -- distinguishes
     * neither, names no user, and names no owner.
     */
    public static function writeReason(string $directory): string
    {
        return match (true) {
            ! is_dir($directory) => 'does not exist',
            ! is_writable($directory) => 'is not writable',
            default => 'rejected the write',
        };
    }
}
