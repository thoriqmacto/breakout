<?php

use App\Services\IdxTicks;

return [
    'seed_dir' => storage_path('app/historical'), // put your 30 CSVs here
    'index_symbols' => ['ADRO','AKRA','AMMN','ANTM','ASII','BRIS','BRMS','BRPT','CPIN','EXCL','ICBP','INCO',
        'INDF','INKP','ISAT','KLBF','MAPI','MBMA','MDKA','MEDC','PANI','PGAS','PGEO','PTBA','PTRO','SMGR',
        'TLKM','TPIA','UNTR','UNVR'],
    'default_lot_size' => 100,
    // Callable that returns the appropriate tick size for a given price
    // according to the IDX variable tick ladder.
    'tick_size' => [IdxTicks::class, 'tickFor'],
    'chunk_size' => 200, // number of rows to insert per batch
];
