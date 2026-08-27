<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    public function destroy(StockbitTokenResolver $resolver, StockbitTokenHealth $health)
    {
        $resolver->forget();

        return ApiResponse::success($health->status(), 'Stockbit token cleared.');
    }
}
