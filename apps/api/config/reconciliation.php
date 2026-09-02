<?php

/*
|--------------------------------------------------------------------------
| Asset reconciliation
|--------------------------------------------------------------------------
|
| The canonical recovery layer that sits between the raw archive and the
| database. Raw files stay authoritative for audit and reprocessing; these
| documents exist so a fresh deployment can rebuild the important state
| without parsing thousands of scattered source files.
|
| One document per asset, plus one lightweight manifest that indexes them.
| Nothing here replaces a raw file, and nothing here is generated from
| anything but canonical data, so the whole layer can be deleted and rebuilt.
|
*/

return [

    /*
    | Bumped whenever the document shape changes in a way an older reader
    | would misinterpret. Restore refuses a version it does not understand
    | rather than guessing at the fields it recognises.
    */
    'schema_version' => 1,

    /*
    | Where the documents live, on the local disk and mirrored to the same
    | path remotely. Sits alongside `seeds/historical` and `broker_summary`
    | rather than inside either, because it is derived from both.
    */
    'path' => env('RECONCILIATION_PATH', 'reconciliation'),

    'local_disk' => env('RECONCILIATION_LOCAL_DISK', 'local'),

    /*
    | Defaults to whatever the CSV mirror uses, so a deployment that has
    | already configured Drive gets reconciliation mirroring without a second
    | variable to discover. Null disables the mirror entirely.
    */
    'mirror_disk' => env('RECONCILIATION_MIRROR_DISK') ?: env('CSV_MIRROR_DISK') ?: null,

    /*
    | Rolling windows, in genuine single-day broker sessions, that the
    | dashboard reports flow balance over. Both are reported with the number
    | of sessions actually available, so "neutral" and "not enough data" stay
    | distinguishable.
    */
    'flow_windows' => [5, 20],

    /*
    | How many trading sessions broker data may trail the latest OHLCV bar
    | before the asset is flagged. One session is normal -- broker summaries
    | are collected after the bar -- so the floor is deliberately above that.
    */
    'broker_lag_warning_sessions' => (int) env('RECONCILIATION_BROKER_LAG_WARNING', 2),

    'broker_lag_error_sessions' => (int) env('RECONCILIATION_BROKER_LAG_ERROR', 5),

    /*
    | How far back integrity checks look for missing single-day broker
    | sessions. Unbounded would flag every session before the asset started
    | being collected daily, which is history rather than a gap.
    */
    'missing_session_lookback' => (int) env('RECONCILIATION_MISSING_SESSION_LOOKBACK', 30),

    /*
    | Caps on what a single document lists, so one badly broken asset cannot
    | produce a document larger than the data it describes. Counts are always
    | exact; only the enumerated examples are capped.
    */
    'max_reported_items' => 50,

    /*
    | Assets reconciled per chunk. Documents are built one at a time so peak
    | memory stays bounded regardless of how many assets exist.
    */
    'chunk_size' => (int) env('RECONCILIATION_CHUNK_SIZE', 25),
];
