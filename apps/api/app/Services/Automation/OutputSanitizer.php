<?php

namespace App\Services\Automation;

/**
 * Makes captured Artisan output safe and bounded before it is stored.
 *
 * Two problems, both of which have to be solved before anything is written:
 *
 *  - A verbose scrape prints a line per ticker, and a `longtext` column with
 *    no ceiling turns run history into the largest table in the database.
 *  - The scraper prints URLs and occasionally echoes request context. Nothing
 *    is supposed to include the bearer, but "supposed to" is not a guarantee
 *    worth betting a credential on when the run history is served to a
 *    browser.
 */
class OutputSanitizer
{
    /**
     * Anything shaped like a credential is replaced wholesale.
     */
    private const PATTERNS = [
        // "Bearer eyJhbGciOi..." in any casing, with or without the scheme.
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
        // A bare JWT: three base64url segments separated by dots.
        '/\beyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]*/',
        // token=..., secret: ..., password = ... and friends.
        '/\b(?:token|bearer|secret|password|api[_-]?key|authorization)\b\s*[:=]\s*\S+/i',
    ];

    private const REDACTION = '[redacted]';

    public function sanitize(?string $output): ?string
    {
        if ($output === null) {
            return null;
        }

        $output = $this->redact($output);

        return $this->truncate($output, (int) config('automation.max_output_length', 20000));
    }

    public function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTION, $text);

            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * Keep the tail. A failing run says why it failed at the end, and the
     * first ten thousand "Fetching historical ..." lines are not the part
     * anyone opens the record to read.
     */
    public function truncate(string $text, int $limit): string
    {
        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        $notice = sprintf("[... %d earlier characters omitted ...]\n", mb_strlen($text) - $limit);

        return $notice.mb_substr($text, -$limit);
    }
}
