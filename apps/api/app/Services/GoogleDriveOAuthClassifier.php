<?php

namespace App\Services;

/**
 * Turns whatever Google said into a status this application can act on.
 *
 * Three callers need the same reading of an OAuth or Drive failure -- the
 * filesystem provider, the gdrive:check command and the backup-status health
 * endpoint -- and they disagreed before this existed. In particular a rejected
 * *client* was reported as a refresh-token problem, sending the operator off to
 * mint a new token that could never have helped.
 *
 * Everything returned here is safe to show a user: the classifier is given
 * error text only, and it emits fixed strings chosen from that text. It never
 * receives or echoes a credential.
 */
class GoogleDriveOAuthClassifier
{
    public const HEALTHY = 'healthy';

    public const NOT_CONFIGURED = 'not_configured';

    public const RENEW_REQUIRED = 'renew_required';

    public const INVALID_CLIENT = 'invalid_client';

    public const SCOPE_ERROR = 'scope_error';

    public const API_DISABLED = 'api_disabled';

    public const UNREACHABLE = 'unreachable';

    public const DRIVE_ERROR = 'drive_error';

    public const UNKNOWN_ERROR = 'unknown_error';

    /**
     * Classify an error message.
     *
     * Order matters. invalid_grant is tested before the generic 403/permission
     * branch because Google's rejection of a dead refresh token can carry both.
     *
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    public function classify(string $error): array
    {
        $haystack = mb_strtolower($error);

        // The provider validates its three credentials before it builds a
        // client, so a missing one never reaches Google at all.
        if ($this->matches($haystack, ['requires google_drive_client_id', 'requires google_drive_client_secret', 'requires google_drive_refresh_token'])) {
            return $this->notConfigured();
        }

        if ($this->matches($haystack, ['could not resolve host', 'connection refused', 'connection timed out', 'curl error', 'ssl certificate', 'network is unreachable', 'timed out'])) {
            return $this->unreachable();
        }

        if (str_contains($haystack, 'invalid_grant')) {
            return $this->renewRequired();
        }

        if ($this->matches($haystack, ['invalid_client', 'unauthorized_client', 'unauthorized'])) {
            return $this->invalidClient();
        }

        if ($this->matches($haystack, ['has not been used', 'accessnotconfigured', 'api has not been enabled'])) {
            return $this->apiDisabled();
        }

        if ($this->matches($haystack, ['insufficientpermissions', 'insufficient permission', 'insufficient scope', 'forbidden', '403'])) {
            return $this->scopeError();
        }

        if ($this->matches($haystack, ['notfound', 'not found', '404'])) {
            return $this->driveError();
        }

        return $this->unknown();
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    public function healthy(): array
    {
        return [
            'status' => self::HEALTHY,
            'refresh_token_status' => 'valid',
            'message' => 'Google Drive OAuth is healthy.',
            'guidance' => [],
        ];
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    public function notConfigured(): array
    {
        return [
            'status' => self::NOT_CONFIGURED,
            'refresh_token_status' => 'not_configured',
            'message' => 'Google Drive is not configured.',
            'guidance' => [
                'Set GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REFRESH_TOKEN, then run php artisan config:cache.',
            ],
        ];
    }

    /**
     * A dead refresh token: the one case where minting a new token is the fix.
     *
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function renewRequired(): array
    {
        return [
            'status' => self::RENEW_REQUIRED,
            'refresh_token_status' => 'renew_required',
            'message' => 'The Google OAuth refresh token was rejected and needs to be replaced.',
            'guidance' => [
                'The refresh token has expired, been revoked, or no longer matches the OAuth client.',
                'Generate a new refresh token using the same client ID and client secret, with offline access and the https://www.googleapis.com/auth/drive scope.',
                'Update GOOGLE_DRIVE_REFRESH_TOKEN on the server, then run php artisan config:cache.',
                'A consent screen still in Testing issues refresh tokens that stop working after seven days.',
            ],
        ];
    }

    /**
     * The client id and secret do not form a valid pair. Regenerating the
     * refresh token first is wasted work, so this deliberately does not
     * suggest it.
     *
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function invalidClient(): array
    {
        return [
            'status' => self::INVALID_CLIENT,
            'refresh_token_status' => 'unknown',
            'message' => 'Google rejected the OAuth client credentials.',
            'guidance' => [
                'Google returns "Unauthorized" as the description for invalid_client. The refresh token was never examined.',
                'Check that GOOGLE_DRIVE_CLIENT_SECRET matches GOOGLE_DRIVE_CLIENT_ID, and that neither carries a stray space, quote or newline.',
                'The client must be of type Web application; Android, iOS and Desktop clients cannot use this grant.',
                'Run php artisan config:cache after correcting either value.',
            ],
        ];
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function scopeError(): array
    {
        return [
            'status' => self::SCOPE_ERROR,
            'refresh_token_status' => 'valid',
            'message' => 'The token was accepted but does not carry the Drive scope this needs.',
            'guidance' => [
                'Re-authorise with https://www.googleapis.com/auth/drive; drive.file and read-only scopes are not enough.',
                'Regenerate the refresh token once the scope is granted.',
            ],
        ];
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function apiDisabled(): array
    {
        return [
            'status' => self::API_DISABLED,
            'refresh_token_status' => 'valid',
            'message' => 'The Google Drive API is not enabled for this OAuth client\'s project.',
            'guidance' => [
                'Enable the Google Drive API under APIs & Services in the Google Cloud console.',
                'It can take a minute to take effect.',
            ],
        ];
    }

    /**
     * A network fault says nothing about the credentials, so the refresh token
     * is reported as unknown rather than blamed.
     *
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function unreachable(): array
    {
        return [
            'status' => self::UNREACHABLE,
            'refresh_token_status' => 'unknown',
            'message' => 'Google could not be reached, so the credentials could not be checked.',
            'guidance' => [
                'The host could not reach accounts.google.com or www.googleapis.com.',
                'This does not mean the refresh token is invalid. Check outbound network access and retry.',
            ],
        ];
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function driveError(): array
    {
        return [
            'status' => self::DRIVE_ERROR,
            'refresh_token_status' => 'valid',
            'message' => 'Drive reported the target folder or file as not found.',
            'guidance' => [
                'If GOOGLE_DRIVE_FOLDER_ID is set, check it holds only the id from the folder URL and that the authorised account can open it.',
                'Leave GOOGLE_DRIVE_FOLDER_ID blank to use My Drive directly.',
            ],
        ];
    }

    /**
     * @return array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}
     */
    private function unknown(): array
    {
        return [
            'status' => self::UNKNOWN_ERROR,
            'refresh_token_status' => 'unknown',
            'message' => 'Google Drive could not be verified.',
            'guidance' => [
                'Check that the Google Drive API is enabled for the OAuth client\'s project.',
                'Check that the three GOOGLE_DRIVE_* credentials are current and belong to one another.',
                'Check that the refresh token was authorised with the full drive scope.',
            ],
        ];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
