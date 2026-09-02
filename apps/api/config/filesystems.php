<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3", "gdrive"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,

            /*
            | Flysystem creates a "private" directory as 0700. That is wrong
            | for this application, because the CLI and the web process are
            | different users: artisan runs as the deploy user and PHP-FPM as
            | www-data, so any directory the scheduler creates becomes one the
            | API cannot even traverse. The file inside is group-readable and
            | entirely innocent; the directory is what locks it away.
            |
            | It is a silent failure, which is what makes it worth configuring
            | rather than remembering: Storage::exists() simply returns false,
            | so a present, correct file reads as missing. That is how a fully
            | built reconciliation layer reported itself as "not built yet"
            | while cold storage held a copy of it.
            |
            | 0775/0664 matches storage/app/private itself, which is already
            | deploy:www-data and group-writable. mkdir() still applies the
            | umask, so this yields 0775 or 0755 depending on the host -- both
            | traversable by the web group, which 0700 never is.
            */
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0664],
                'dir' => ['public' => 0755, 'private' => 0775],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Google Drive is durable cold storage for the historical data artifacts
         * (Stockbit JSON payloads and OHLCV seed CSVs). It is never a query
         * layer -- `price_bars` / `features_daily` remain the source of truth.
         *
         * The driver is registered by App\Providers\GoogleDriveServiceProvider.
         */
        'gdrive' => [
            'driver' => 'gdrive',

            // OAuth 2.0 against a personal Google account. A service account
            // cannot be used: Google gives them no storage quota, so one can
            // create folders in My Drive but never own a file in them.
            'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
            'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
            'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),

            // Optional parent folder. Blank means the authenticated user's My
            // Drive root, which is the normal setup.
            'folderId' => env('GOOGLE_DRIVE_FOLDER_ID'),

            // Folder created/used under the parent. A display path, not an id.
            'root' => env('GOOGLE_DRIVE_ROOT', 'breakout-data'),

            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
