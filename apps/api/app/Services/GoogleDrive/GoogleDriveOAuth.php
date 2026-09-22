<?php

namespace App\Services\GoogleDrive;

use App\Support\GoogleDriveTokenStore;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Oauth2 as GoogleOauth2Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The consent round-trip that replaces pasting a token into .env.
 *
 * Adapted from the same flow in the keje repository, with one deliberate
 * difference: the grant here belongs to the installation, not to a user.
 * Drive is written to by the scheduled collectors and the mirror push, which
 * run under cron and the queue worker with nobody signed in, so a per-user
 * connection row would leave those jobs with no credential to find. A person
 * signs in to *perform* the connection; what is stored is the application's.
 *
 * State is generated here, cached, and verified on callback. Without it the
 * callback would accept an authorization code obtained by anyone, which is a
 * CSRF that swaps the installation's Drive for the attacker's -- and since
 * this credential is what every backup is written through, that is worth more
 * than the usual care.
 */
class GoogleDriveOAuth
{
    private const STATE_TTL_MINUTES = 15;

    private const STATE_PREFIX = 'google-drive:oauth:';

    public function __construct(
        private readonly GoogleDriveTokenStore $tokens,
    ) {}

    /** True once client id, secret and redirect URI are all present. */
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    /**
     * The consent URL, plus the one-time state that proves the callback
     * belongs to a flow this server started.
     */
    public function authorizationUrl(?string $startedBy = null): string
    {
        $state = Str::random(40);

        Cache::put(
            self::STATE_PREFIX.$state,
            ['started_by' => $startedBy, 'at' => now()->toIso8601String()],
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Verify and burn a state.
     *
     * Single use: the key is forgotten as it is read, so a callback replayed
     * within the TTL fails.
     */
    public function consumeState(string $state): bool
    {
        return Cache::pull(self::STATE_PREFIX.$state) !== null;
    }

    /**
     * Exchange the authorization code and store the refresh token.
     *
     * @throws RuntimeException
     */
    public function completeConnection(string $code): void
    {
        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (! is_array($token) || isset($token['error'])) {
            // The error names a request carrying the client secret, so only
            // the code is repeated back and never the description.
            $code = is_array($token) && is_string($token['error'] ?? null) ? $token['error'] : 'unknown_error';

            throw new RuntimeException("Google rejected the authorization ({$code}).");
        }

        $refreshToken = is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : null;

        if ($refreshToken === null || trim($refreshToken) === '') {
            // Google returns a refresh token on first consent and on
            // re-consent only. The client asks for prompt=consent precisely so
            // this does not happen, so reaching here means the grant was made
            // some other way.
            throw new RuntimeException(
                'Google did not return a refresh token. Remove this application from your Google '
                .'account permissions and connect again so the consent screen is shown.'
            );
        }

        $this->tokens->put($refreshToken, $this->accountEmail($client, $token));
    }

    /**
     * Revoke the grant at Google, then drop our copy.
     *
     * Our copy goes regardless. A revoke that fails because the grant is
     * already gone must not leave a token behind that the status card would
     * then report as connected.
     */
    public function disconnect(): void
    {
        $refreshToken = $this->tokens->get();

        if ($refreshToken !== null && $this->isConfigured()) {
            try {
                $this->client()->revokeToken($refreshToken);
            } catch (Throwable) {
                // Already revoked, or Google unreachable. Either way, drop it.
            }
        }

        $this->tokens->forget();
    }

    /**
     * Which Google account consented, for the status card to display.
     *
     * Best effort: a connection that works but cannot name its account is
     * still a working connection, so a failure here leaves it unknown rather
     * than undoing the grant.
     */
    private function accountEmail(GoogleClient $client, array $token): ?string
    {
        try {
            $client->setAccessToken($token);

            $info = (new GoogleOauth2Service($client))->userinfo->get();
            $email = $info->getEmail();

            return is_string($email) && $email !== '' ? $email : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): GoogleClient
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Google Drive OAuth is not configured. Set GOOGLE_DRIVE_CLIENT_ID, '
                .'GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REDIRECT_URI.'
            );
        }

        $client = new GoogleClient;
        $client->setClientId($this->clientId());
        $client->setClientSecret($this->clientSecret());
        $client->setRedirectUri($this->redirectUri());
        $client->setApplicationName(config('app.name', 'Breakout').' Google Drive');

        // The same scope the disk has always used. Narrowing to drive.file
        // would be tighter, but it only sees files the granting client
        // created -- every backup written under the previous grant would stop
        // being visible, which is a data-loss-shaped surprise rather than a
        // security improvement.
        $client->addScope(GoogleDriveService::DRIVE);

        // Reading the account email back is what lets the card say which Drive
        // is being written to, which is the mistake worth catching early.
        $client->addScope(GoogleOauth2Service::USERINFO_EMAIL);

        // offline + consent is what actually yields a refresh token: Google
        // returns one on first authorization or re-consent only, so asking for
        // consent explicitly is what keeps the reconnect button working.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function clientId(): string
    {
        return trim((string) config('filesystems.disks.gdrive.clientId', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) config('filesystems.disks.gdrive.clientSecret', ''));
    }

    private function redirectUri(): string
    {
        return trim((string) config('google_drive.redirect_uri', ''));
    }
}
