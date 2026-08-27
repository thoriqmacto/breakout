<?php

use App\Services\IdxTicks;

return [
    /*
    |--------------------------------------------------------------------------
    | OHLCV seed CSV directory
    |--------------------------------------------------------------------------
    |
    | Where the per-symbol bar CSVs are read and written.
    |
    | The default lives inside the repository, which is right for development
    | and for the committed bootstrap data. It is the wrong place on a server:
    | the deploy runs `git reset --hard` in a persistent checkout, so every
    | deploy rewrites those tracked files back to whatever was committed and
    | discards every bar the scheduler has appended since.
    |
    | Point CSV_SEED_DIR at a path outside the working tree in production --
    | somewhere the deploy cannot reach, e.g.
    |
    |     CSV_SEED_DIR=/var/www/breakout-data/historical
    |
    | The mirror is a backup, not a substitute for this: restoring from Drive
    | costs an API call per symbol and only works while the mirror is healthy.
    |
    */
    'seed_dir' => env('CSV_SEED_DIR') ?: database_path('seeders/data/historical'),
    'default_lot_size' => 100,
    'tick_size' => [IdxTicks::class, 'tickFor'], // according to the IDX variable tick ladder.
    'chunk_size' => 200, // number of rows to insert per batch

    /*
    |--------------------------------------------------------------------------
    | Seed CSV Mirror
    |--------------------------------------------------------------------------
    |
    | The seed CSVs are always built on local disk so CsvBars keeps its atomic
    | temp-file + rename() semantics and the read/merge/write loop never talks
    | to a remote API. A mirror disk (e.g. "gdrive" or "s3") is an optional
    | durable copy that is hydrated before a run and flushed after one.
    |
    | A null mirror_disk disables mirroring entirely, which is the default and
    | leaves the pipeline byte-for-byte identical to pure-local behaviour.
    |
    */
    'mirror_disk' => env('CSV_MIRROR_DISK') ?: null,
    'mirror_path' => env('CSV_MIRROR_PATH', 'seeds/historical'),
];
