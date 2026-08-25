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

/**
 * Registers the "gdrive" filesystem driver so Google Drive can back any disk
 * through the regular Storage facade.
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
            $keyFile = $this->resolveKeyFile($config['keyFile'] ?? null);

            $folderId = isset($config['folderId']) ? trim((string) $config['folderId']) : '';
            $teamDriveId = isset($config['teamDriveId']) ? trim((string) $config['teamDriveId']) : '';

            if ($folderId === '' && $teamDriveId === '') {
                throw new InvalidArgumentException(
                    'The gdrive disk requires GOOGLE_DRIVE_TEAM_DRIVE_ID (a Shared Drive) or '.
                    'GOOGLE_DRIVE_FOLDER_ID (a My Drive folder shared with the service account).'
                );
            }

            $client = new GoogleClient;
            $client->setAuthConfig($keyFile);
            $client->addScope(GoogleDriveService::DRIVE);

            $root = isset($config['root']) && trim((string) $config['root']) !== ''
                ? trim((string) $config['root'])
                : 'breakout-data';

            // A Shared Drive owns its own files, so the service account never
            // needs storage of its own. Google removed service-account storage
            // quota, so an account writing into a plain My Drive folder can
            // create folders (which cost nothing) but not files. teamDriveId
            // therefore takes precedence when both are set.
            $adapter = new GoogleDriveAdapter(
                new GoogleDriveService($client),
                $root,
                $teamDriveId !== ''
                    ? ['teamDriveId' => $teamDriveId]
                    : ['sharedFolderId' => $folderId]
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Resolve the service-account JSON path, accepting paths relative to the
     * application root so `.env` can carry `storage/app/google/...`.
     */
    private function resolveKeyFile(mixed $keyFile): string
    {
        $keyFile = is_string($keyFile) ? trim($keyFile) : '';

        if ($keyFile === '') {
            throw new InvalidArgumentException(
                'The gdrive disk requires GOOGLE_DRIVE_KEY_FILE: a path to the Google service-account JSON.'
            );
        }

        $path = str_starts_with($keyFile, DIRECTORY_SEPARATOR)
            ? $keyFile
            : base_path($keyFile);

        if (! is_file($path)) {
            throw new InvalidArgumentException(
                "Google service-account file not found at [{$path}]. Set GOOGLE_DRIVE_KEY_FILE to the credentials JSON."
            );
        }

        return $path;
    }
}
