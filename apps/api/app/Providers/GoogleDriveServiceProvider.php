<?php

namespace App\Providers;

use App\Support\GoogleDriveTokenStore;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use Throwable;

/**
 * Registers the "gdrive" filesystem driver so Google Drive can back any disk
 * through the regular Storage facade.
 *
 * Authentication is OAuth 2.0 against a personal Google account: a client id
 * and secret from the environment, and a long-lived refresh token from
 * GoogleDriveTokenStore -- granted through the consent screen on the Backups
 * page, or read from GOOGLE_DRIVE_REFRESH_TOKEN where nothing has been
 * connected yet. There is no credentials file. A service account was tried
 * first and cannot work here -- Google gives service accounts no storage
 * quota, so one can create folders in My Drive but never own a file in them.
 *
 * Drive is durable cold storage for file artifacts only. The relational tables
 * (`price_bars`, `features_daily`) stay the query layer, and secrets such as
 * the Stockbit bearer are never routed here.
 */
class GoogleDriveServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('gdrive', function ($app, array $config): FilesystemAdapter {
            $clientId = $this->requireConfig($config, 'clientId', 'GOOGLE_DRIVE_CLIENT_ID');
            $clientSecret = $this->requireConfig($config, 'clientSecret', 'GOOGLE_DRIVE_CLIENT_SECRET');

            // The grant made through the dashboard's connect button, falling
            // back to GOOGLE_DRIVE_REFRESH_TOKEN. The store reads that
            // variable itself when nothing has been connected here yet, so an
            // installation still running on a pasted token is unaffected until
            // somebody presses the button.
            $refreshToken = trim((string) app(GoogleDriveTokenStore::class)->get());

            if ($refreshToken === '') {
                throw new InvalidArgumentException(
                    'Google Drive is not connected. Connect it from the Backups & Recovery page, '
                    .'or set GOOGLE_DRIVE_REFRESH_TOKEN.'
                );
            }

            $client = new GoogleClient;
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setApplicationName(config('app.name', 'Breakout').' Google Drive');
            $client->addScope(GoogleDriveService::DRIVE);

            $this->authenticate($client, $refreshToken);

            $root = isset($config['root']) && trim((string) $config['root']) !== ''
                ? trim((string) $config['root'])
                : 'breakout-data';

            $folderId = isset($config['folderId']) ? trim((string) $config['folderId']) : '';

            // The adapter defaults useDisplayPaths to true, so $root is resolved
            // as a *display path* under the parent and created when missing --
            // not treated as a file id. With no folderId the parent is the
            // authenticated user's My Drive, giving My Drive/breakout-data.
            $options = $folderId !== '' ? ['sharedFolderId' => $folderId] : [];

            $adapter = new GoogleDriveAdapter(
                new GoogleDriveService($client),
                $root,
                $options
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Exchange the refresh token for an access token.
     *
     * Done once per resolved disk rather than per operation: Laravel memoises
     * a disk for the life of the process, and the adapter calls its own
     * refreshToken() before each request, which re-fetches when the access
     * token has expired. google/apiclient re-injects the refresh token into
     * the stored credentials when Google's response omits it, so a
     * long-running worker keeps refreshing indefinitely.
     */
    private function authenticate(GoogleClient $client, string $refreshToken): void
    {
        try {
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable $e) {
            // Never surface the exception verbatim: the request it describes
            // carries the client secret and refresh token.
            throw new InvalidArgumentException(
                'Google Drive OAuth request failed: '.$e->getMessage().' '.
                'Check GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET, that Drive is '.
                'connected on the Backups & Recovery page, and that the host can reach '.
                'accounts.google.com.'
            );
        }

        // A rejected grant comes back as a normal array with an error key
        // rather than as an exception.
        //
        // Both halves are reported. The code is the machine-readable part and
        // is what the gdrive:check hints match on, while the description is
        // often uselessly terse on its own -- a wrong client secret answers
        // {"error": "invalid_client", "error_description": "Unauthorized"},
        // and reporting only "Unauthorized" hides which credential was wrong.
        if (is_array($token) && isset($token['error'])) {
            $code = is_string($token['error']) ? $token['error'] : '';
            $description = is_string($token['error_description'] ?? null)
                ? $token['error_description']
                : '';

            $detail = match (true) {
                $code !== '' && $description !== '' => "{$code} ({$description})",
                $code !== '' => $code,
                $description !== '' => $description,
                default => 'the token endpoint rejected the request without saying why',
            };

            throw new InvalidArgumentException("Google Drive OAuth failed: {$detail}");
        }

        if (! is_array($token) || ! isset($token['access_token'])) {
            throw new InvalidArgumentException(
                'Google Drive OAuth returned no access token. Reconnect Drive from the '.
                'Backups & Recovery page.'
            );
        }
    }

    /**
     * Read a required credential, naming the environment variable that sets it.
     */
    private function requireConfig(array $config, string $key, string $envVar): string
    {
        $value = isset($config[$key]) ? trim((string) $config[$key]) : '';

        if ($value === '') {
            throw new InvalidArgumentException("The gdrive disk requires {$envVar}.");
        }

        return $value;
    }
}
