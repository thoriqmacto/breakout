<?php

namespace App\Providers;

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
 * Authentication is OAuth 2.0 against a personal Google account: a client id,
 * client secret and long-lived refresh token, all from the environment. There
 * is no credentials file. A service account was tried first and cannot work
 * here -- Google gives service accounts no storage quota, so one can create
 * folders in My Drive but never own a file in them.
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
            $refreshToken = $this->requireConfig($config, 'refreshToken', 'GOOGLE_DRIVE_REFRESH_TOKEN');

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
                'Check GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET and '.
                'GOOGLE_DRIVE_REFRESH_TOKEN, and that the host can reach accounts.google.com.'
            );
        }

        // A rejected grant comes back as a normal array with an error key
        // rather than as an exception.
        if (is_array($token) && isset($token['error'])) {
            $detail = is_string($token['error_description'] ?? null)
                ? $token['error_description']
                : (string) $token['error'];

            throw new InvalidArgumentException("Google Drive OAuth failed: {$detail}");
        }

        if (! is_array($token) || ! isset($token['access_token'])) {
            throw new InvalidArgumentException(
                'Google Drive OAuth returned no access token. Regenerate GOOGLE_DRIVE_REFRESH_TOKEN.'
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
