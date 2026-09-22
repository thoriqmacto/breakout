<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GoogleDrive\GoogleDriveOAuth;
use App\Support\GoogleDriveTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connecting Google Drive from the dashboard instead of the OAuth Playground.
 *
 * The whole flow stays on the API: the client secret and the refresh token
 * never reach Next.js, which only ever sends the browser to the URL this
 * hands back and reads the outcome off the query string afterwards.
 */
class GoogleDriveOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleDriveOAuth $oauth,
        private readonly GoogleDriveTokenStore $tokens,
    ) {}

    /**
     * What the backups page needs to decide which button to show.
     *
     * Never the token. The fingerprint is four characters of a hash, which
     * distinguishes one grant from the next and is useless to anyone reading
     * it over the wire.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'configured' => $this->oauth->isConfigured(),
                'connected' => $this->tokens->has(),
                'source' => $this->tokens->source(),
                'account' => $this->tokens->account(),
                'fingerprint' => $this->tokens->fingerprint(),
                'connected_at' => $this->tokens->connectedAt()?->toIso8601String(),
            ],
        ]);
    }

    /** Hand the dashboard a consent URL to send the browser to. */
    public function redirect(Request $request): JsonResponse
    {
        if (! $this->oauth->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Drive OAuth is not configured on the server. Set '
                    .'GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REDIRECT_URI, '
                    .'and register the redirect URI on the OAuth client in Google Cloud Console.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'authorization_url' => $this->oauth->authorizationUrl($request->user()?->email),
            ],
        ]);
    }

    /**
     * Google's redirect back, necessarily unauthenticated.
     *
     * The browser arrives here straight from accounts.google.com with no
     * Authorization header, so the single-use `state` is the only thing
     * proving this callback belongs to a flow this server started. Without
     * it, anyone could drive the endpoint with their own authorization code
     * and point every backup at their own Drive.
     */
    public function callback(Request $request): RedirectResponse
    {
        $target = $this->returnUrl();

        if ($request->filled('error')) {
            return redirect("{$target}?drive=denied");
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return redirect("{$target}?drive=invalid");
        }

        if (! $this->oauth->consumeState($state)) {
            // Unknown, expired, or already used.
            return redirect("{$target}?drive=invalid_state");
        }

        try {
            $this->oauth->completeConnection($code);
        } catch (Throwable $e) {
            // Logged here, never put in the URL: the message describes a
            // request that carried the client secret.
            Log::warning('Google Drive OAuth callback failed.', ['reason' => $e->getMessage()]);

            return redirect("{$target}?drive=failed");
        }

        return redirect("{$target}?drive=connected");
    }

    /** Revoke the grant and forget it. */
    public function destroy(): JsonResponse
    {
        $this->oauth->disconnect();

        return response()->json([
            'status' => 'success',
            'message' => 'Google Drive disconnected.',
        ]);
    }

    /**
     * Where the browser lands afterwards.
     *
     * config(), not env(): a cached config leaves env() null at runtime, which
     * would send every production callback to localhost.
     */
    private function returnUrl(): string
    {
        $frontend = rtrim((string) config('app.frontend_url', ''), '/');

        if ($frontend === '') {
            $frontend = rtrim((string) config('app.url', ''), '/');
        }

        $path = '/'.ltrim((string) config('google_drive.return_path', '/dashboard/backups'), '/');

        return $frontend.$path;
    }
}
