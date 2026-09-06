<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Read the Stockbit token's state, and replace it.
 *
 * The one rule this controller exists to keep: the bearer goes in and never
 * comes back out. There is no endpoint that returns it, no field on a model
 * that stores it in the clear, and nothing here writes it to a log. What a
 * client can learn is whether one is configured, where it came from, its last
 * four characters, and when it expires.
 *
 * Persistence goes through the existing StockbitTokenResolver, which encrypts
 * it into the file store and mirrors it into the runtime cache -- the same
 * path `stockbit:token:set` uses, so the CLI and the dashboard cannot disagree
 * about where the token lives.
 */
class StockbitTokenController extends ApiController
{
    public function show(StockbitTokenHealth $health)
    {
        return ApiResponse::success($health->status());
    }

    public function renew(
        Request $request,
        StockbitTokenResolver $resolver,
        StockbitTokenHealth $health,
        AutomationAlerts $alerts,
    ) {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:8192'],
        ]);

        $token = trim((string) $data['token']);

        // Tolerate a pasted "Bearer eyJ..." -- it is what the browser devtools
        // copy button produces, and silently storing the scheme would break
        // every subsequent request in a way that is hard to see.
        if (preg_match('/^Bearer\s+/i', $token) === 1) {
            $token = (string) preg_replace('/^Bearer\s+/i', '', $token);
        }

        if (substr_count($token, '.') !== 2) {
            return ApiResponse::error(
                'That does not look like a JWT. A Stockbit bearer token has three dot-separated segments.',
                422,
                ['token' => ['The value must be a JWT with three dot-separated segments.']],
            );
        }

        if (preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]*$/', $token) !== 1) {
            return ApiResponse::error(
                'That does not look like a JWT. Its segments must be base64url encoded.',
                422,
                ['token' => ['The value must be a base64url-encoded JWT.']],
            );
        }

        $expiresAt = StockbitExodusClient::jwtExpiresAt($token);

        if ($expiresAt !== null && Carbon::instance($expiresAt)->isPast()) {
            // Storing an expired token would replace a possibly-working one
            // with a certainly-broken one.
            return ApiResponse::error(
                'That token expired on '.Carbon::instance($expiresAt)->toDayDateTimeString().'. Paste a fresh one.',
                422,
                ['token' => ['The token is already expired.']],
            );
        }

        $resolver->persist($token);

        // Renewing is exactly the action the reminder was asking for, so the
        // dashboard warning clears itself rather than needing to be dismissed.
        $status = $health->status();

        if (! $health->needsAttention($status)) {
            $alerts->resolve(AutomationAlert::TYPE_STOCKBIT_TOKEN, 'renewal-required');
        }

        // The response carries the new status, never the token.
        return ApiResponse::success($status, 'Stockbit token saved.');
    }

    /**
     * Drive a headless browser through the portal's login form and store the
     * bearer it issues.
     *
     * This is the one place in the application that accepts a portal
     * password, and it is deliberately narrow about what it does with it: the
     * credentials are used for a single attempt, passed to the child process
     * on stdin, and never written to the database, a log, a cache or the
     * response. There is no "remember these" and no scheduled variant --
     * automation would mean storing the password, which is a different
     * decision from running one login on request.
     *
     * Off unless BROWSER_AUTH_ENABLED is set, because turning it on changes
     * what a compromise of this server costs: a stolen bearer expires, and a
     * stolen password does not.
     */
    public function browserLogin(
        Request $request,
        BrowserTokenExtractor $extractor,
        StockbitTokenResolver $resolver,
        StockbitTokenHealth $health,
        AutomationAlerts $alerts,
    ) {
        if (! $extractor->enabled()) {
            return ApiResponse::error(
                'Headless login is not configured on this server.',
                503,
            );
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:320'],
            'password' => ['required', 'string', 'max:512'],
        ]);

        // Throttled by user: a login form driven by a real browser is an
        // expensive operation against a third party, and repeated failures
        // against a portal are how an account gets locked.
        $key = 'browser-login:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error(
                sprintf(
                    'Too many login attempts. Try again in %d seconds.',
                    RateLimiter::availableIn($key),
                ),
                429,
            );
        }

        // One browser at a time. Each run is a Chromium process; several at
        // once is the fastest way to exhaust memory on a small VPS.
        $lock = Cache::lock('browser-login:running', $this->browserLockSeconds());

        if (! $lock->get()) {
            return ApiResponse::error('A headless login is already in progress.', 409);
        }

        RateLimiter::hit($key, 900);

        try {
            $result = $extractor->extract($data['username'], $data['password']);
        } catch (BrowserTokenExtractionException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                $exception->failureCode === BrowserTokenExtractor::INVALID_CREDENTIALS ? 422 : 502,
                ['code' => [$exception->failureCode]],
            );
        } finally {
            $lock->release();
        }

        $expiresAt = StockbitExodusClient::jwtExpiresAt($result['token']);

        if ($expiresAt !== null && Carbon::instance($expiresAt)->isPast()) {
            return ApiResponse::error(
                'The portal issued a token that is already expired.',
                502,
                ['code' => ['EXPIRED_TOKEN']],
            );
        }

        $resolver->persist($result['token']);

        $status = $health->status();

        if (! $health->needsAttention($status)) {
            $alerts->resolve(AutomationAlert::TYPE_STOCKBIT_TOKEN, 'renewal-required');
        }

        // Same rule as renew(): the status, never the token. `source` says
        // which listener saw it, which is the one diagnostic worth having
        // when a portal changes shape.
        return ApiResponse::success(
            $status + ['captured_from' => $result['source'], 'elapsed_ms' => $result['elapsed_ms']],
            'Signed in and stored the token.',
        );
    }

    private function browserLockSeconds(): int
    {
        return max(30, (int) config('browser_auth.timeout_seconds', 60) + 30);
    }

    public function destroy(StockbitTokenResolver $resolver, StockbitTokenHealth $health)
    {
        $resolver->forget();

        return ApiResponse::success($health->status(), 'Stockbit token cleared.');
    }
}
