<?php

namespace App\Services\Stockbit;

use App\Services\StockbitExodusClient;

/**
 * Ask the API whether a token actually works, before anything relies on it.
 *
 * A JWT carries its own expiry, and every check in this system used to read
 * that and stop there. The claim is the token's opinion of itself, not the
 * server's: a bearer can be revoked, rotated, or unbound from its session and
 * still say it is good for another ten hours. `automation:token-check` reads
 * `exp` and reports health; the scrape is what discovers the truth, hours
 * later, part-way through the universe.
 *
 * That gap became a loop once renewal was automated. The browser profile held
 * a bearer the API had stopped accepting, its own app kept sending that bearer
 * on every request, the extractor captured it from the request header --
 * before any response existed to say it had been refused -- and stored it
 * again. Same token, same fingerprint, same 401, every time, with each layer
 * reporting success.
 *
 * One authenticated call closes it. Cheap, read-only, and the only question
 * that matters: does the server accept this.
 */
class StockbitTokenVerifier
{
    /** The API accepted it. */
    public const OK = 'ok';

    /** The API refused it. Renewing again will not help; the session is gone. */
    public const REJECTED = 'rejected';

    /**
     * The API could not be reached, so nothing was learned.
     *
     * Deliberately distinct from rejection: a network failure is not evidence
     * against a token, and treating it as one would discard a working bearer
     * every time the server had a bad minute.
     */
    public const UNKNOWN = 'unknown';

    public function __construct(private readonly StockbitExodusClient $api) {}

    /**
     * @return array{status: string, message: ?string}
     */
    public function verify(string $token): array
    {
        $symbol = (string) config('stockbit.verify_symbol', 'BBCA');

        // A separate client instance would re-read the stored bearer, which is
        // the one being replaced. This asks about the token in hand.
        $this->api->setBearer($token);

        $response = $this->api->tickerProfile($symbol);

        $error = $response['error'] ?? null;

        if ($error === null) {
            return ['status' => self::OK, 'message' => null];
        }

        if ($error === 'unauthorized') {
            return [
                'status' => self::REJECTED,
                'message' => 'The portal refused this token. Its expiry has not passed, so the '
                    .'session behind it has been ended, revoked or rotated -- capturing it again '
                    .'returns the same dead token. Sign in once interactively to establish a new '
                    .'session: `php artisan browser:token`.',
            ];
        }

        return [
            'status' => self::UNKNOWN,
            'message' => sprintf('Could not verify the token (%s).', (string) $error),
        ];
    }
}
