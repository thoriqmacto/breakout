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
            'keyFile' => env('GOOGLE_DRIVE_KEY_FILE'),   // path to the service-account JSON
            'folderId' => env('GOOGLE_DRIVE_FOLDER_ID'), // ID of the My Drive folder shared with the service account
            // ID of a Shared Drive. Preferred: a Shared Drive owns its files, so
            // the service account does not need storage quota of its own. Takes
            // precedence over folderId when both are set.
            'teamDriveId' => env('GOOGLE_DRIVE_TEAM_DRIVE_ID'),
            'root' => env('GOOGLE_DRIVE_ROOT', 'breakout-data'), // folder created inside the shared folder
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
