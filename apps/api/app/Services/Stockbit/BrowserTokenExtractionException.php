<?php

namespace App\Services\Stockbit;

use RuntimeException;

/**
 * Carries a machine-readable code alongside a message safe to show a person.
 *
 * The code is what callers branch on. The message is written for whoever is
 * looking at the dashboard and deliberately never quotes the child process
 * verbatim, because that output can carry the login URL, a selector, or a
 * fragment of the portal's markup.
 */
class BrowserTokenExtractionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $evidence  What the browser observed,
     *                                               for a caller that can show
     *                                               more than one sentence.
     */
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly ?array $evidence = null,
    ) {
        // Not `$code`: Exception already declares one, as a non-readonly int,
        // and redeclaring it is a fatal error rather than a shadow.
        parent::__construct($message);
    }
}
