<?php

use App\Services\IdxTicks;

return [
    'seed_dir' => database_path('seeders/data/historical'), // put your 30 CSVs here
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
