<?php

namespace App\Services\Indexes;

use RuntimeException;

/**
 * A catalogue read that did not produce a list.
 *
 * Carries a reason code rather than only a sentence, so the command can decide
 * how loudly to report it: a page whose markup changed needs a person, a
 * network blip needs tomorrow's run.
 */
class IndexCatalogReadException extends RuntimeException
{
    public const NOT_INSTALLED = 'NOT_INSTALLED';

    public const BROWSER_LAUNCH_FAILED = 'BROWSER_LAUNCH_FAILED';

    public const NAVIGATION_FAILED = 'NAVIGATION_FAILED';

    public const NO_SYMBOLS_FOUND = 'NO_SYMBOLS_FOUND';

    /**
     * The site answered with a sign-in page.
     *
     * Its own code because the remedy is the opposite of NO_SYMBOLS_FOUND's:
     * nothing about the constituent markup is wrong and no selector would
     * help. The page needs a session, or the list needs pasting.
     */
    public const LOGIN_REQUIRED = 'LOGIN_REQUIRED';

    /**
     * The saved profile was open elsewhere -- the token renewal, most likely.
     *
     * Transient, and not worth a warning: the next scheduled read gets it.
     */
    public const PROFILE_BUSY = 'PROFILE_BUSY';

    public const TIMEOUT = 'TIMEOUT';

    public const UNEXPECTED = 'UNEXPECTED';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $evidence = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Whether a person has to do something, or whether the next run will do.
     */
    public function needsAttention(): bool
    {
        return in_array($this->reason, [self::NOT_INSTALLED, self::NO_SYMBOLS_FOUND, self::LOGIN_REQUIRED], true);
    }
}
