<?php

namespace App\Services\Stockbit;

use App\Support\StockbitCredentialStore;

/**
 * One headless renewal, callable from anywhere that finds itself without a
 * usable token.
 *
 * This was the body of `automation:token-refresh` and nothing else could reach
 * it, which mattered the first time a scrape met a 401. The scheduled renewal
 * fires on the clock -- missing, expired, or inside the renewal window -- and
 * a token can stop working long before its `exp` says it should: revoked,
 * rotated, or bound to a session the portal ended. When that happens the
 * refresh job has already looked at the expiry, called the token healthy, and
 * gone back to sleep, so the only component that learns the truth is whichever
 * scrape gets the 401. It needs to be able to do something about it.
 *
 * Deliberately not a command: a command cannot be called mid-scrape without
 * either an Artisan round trip or the interactivity problem that made a
 * scheduled job stop and wait at a password prompt no one was there to answer.
 */
class StockbitTokenRenewer
{
    /** Headless login is switched off, so there is nothing to try. */
    public const NOT_CONFIGURED = 'not_configured';

    /** No saved profile and no stored credentials: nothing to sign in with. */
    public const NO_CREDENTIALS = 'no_credentials';

    /** The browser ran and did not come back with a token. */
    public const EXTRACTION_FAILED = 'extraction_failed';

    /**
     * A token was captured and the API refused it.
     *
     * The important case, and the one that used to be invisible. The extractor
     * reads the bearer off a request header, which happens before any response
     * exists to say the request was rejected -- so a profile whose session has
     * ended keeps handing over the same dead token, and every layer above
     * reports a successful renewal. Storing it overwrites whatever was there
     * with something known not to work.
     */
    public const REJECTED_BY_API = 'rejected_by_api';

    public function __construct(
        private readonly BrowserTokenExtractor $extractor,
        private readonly StockbitCredentialStore $credentials,
        private readonly StockbitTokenResolver $resolver,
        private readonly StockbitTokenVerifier $verifier,
    ) {}

    /**
     * Whether a renewal could even be attempted right now.
     *
     * Cheap: it launches nothing. Callers use it to decide whether to promise
     * an automatic retry before spending thirty seconds finding out.
     */
    public function available(): bool
    {
        if (! $this->extractor->enabled()) {
            return false;
        }

        return $this->hasProfile() || $this->credentials->get() !== null;
    }

    /**
     * Sign in and store whatever bearer the portal issues.
     *
     * Never throws: a caller in the middle of a scrape wants an answer it can
     * branch on, not a second failure mode layered over the first. The reason
     * is a stable code and the message is already redacted -- the extractor's
     * own text, which says what to do about each kind of failure.
     *
     * @return array{
     *     renewed: bool,
     *     reason: ?string,
     *     message: ?string,
     *     token: ?string,
     *     source: ?string,
     *     elapsed_ms: ?int,
     *     used_profile: bool,
     * }
     */
    public function renew(): array
    {
        if (! $this->extractor->enabled()) {
            return $this->refused(
                self::NOT_CONFIGURED,
                'Headless login is switched off, so the token cannot be renewed automatically. '
                .'Set BROWSER_AUTH_ENABLED and BROWSER_AUTH_LOGIN_URL, or renew by hand.',
            );
        }

        // A saved profile that is still signed in needs no password at all,
        // which is the better arrangement by some distance: the thing a stolen
        // disk gives up is then a session that can be revoked, not a password
        // that cannot.
        $usedProfile = $this->hasProfile();
        $stored = $this->credentials->get();

        if ($stored === null && ! $usedProfile) {
            return $this->refused(
                self::NO_CREDENTIALS,
                $this->credentials->exists()
                    ? 'Stored credentials could not be decrypted with this APP_KEY. Run '
                        .'`php artisan stockbit:credentials` to replace them.'
                    : 'No saved browser profile and no stored credentials, so the token cannot be '
                        .'renewed automatically. Set BROWSER_AUTH_PROFILE_DIR and sign in once, or '
                        .'run `php artisan stockbit:credentials`.',
                $usedProfile,
            );
        }

        try {
            $result = $this->extractor->extract($stored['username'] ?? null, $stored['password'] ?? null);
        } catch (BrowserTokenExtractionException $exception) {
            return $this->refused(
                $exception->failureCode ?: self::EXTRACTION_FAILED,
                'Automatic renewal failed: '.$exception->getMessage(),
                $usedProfile,
            );
        }

        // Proven before it is trusted. A captured token is a token the app was
        // sending, not necessarily one the API still accepts, and the two stop
        // being the same thing the moment a session ends.
        $verification = $this->verifier->verify($result['token']);

        if ($verification['status'] === StockbitTokenVerifier::REJECTED) {
            return $this->refused(
                self::REJECTED_BY_API,
                (string) $verification['message'],
                $usedProfile,
            );
        }

        // An unreachable API is not evidence against the token: storing it is
        // the same decision as before this check existed, and refusing it
        // would throw away a working bearer over a bad minute on the network.
        $this->resolver->persist($result['token']);

        return [
            'renewed' => true,
            'reason' => null,
            'message' => null,
            'token' => $result['token'],
            'source' => $result['source'],
            'elapsed_ms' => $result['elapsed_ms'],
            'used_profile' => $usedProfile,
        ];
    }

    /**
     * @return array{renewed: bool, reason: ?string, message: ?string, token: ?string, source: ?string, elapsed_ms: ?int, used_profile: bool}
     */
    private function refused(string $reason, string $message, bool $usedProfile = false): array
    {
        return [
            'renewed' => false,
            'reason' => $reason,
            'message' => $message,
            'token' => null,
            'source' => null,
            'elapsed_ms' => null,
            'used_profile' => $usedProfile,
        ];
    }

    /**
     * A misconfigured profile directory throws rather than answering, and that
     * is not this method's business to report -- the extraction raises it with
     * the path and the user in the message.
     */
    private function hasProfile(): bool
    {
        try {
            return $this->extractor->profileDir() !== null;
        } catch (BrowserTokenExtractionException) {
            return false;
        }
    }
}
