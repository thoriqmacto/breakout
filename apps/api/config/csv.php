<?php

use App\Services\IdxTicks;

return [
    'seed_dir' => database_path('seeders/data/historical'), // put your 30 CSVs here
    'index_symbols' => [
        'ACES','ADRO','AKRA','AMMN','ANTM','ASII','BRIS','BRMS','BRPT','CPIN','DCII','EXCL','GEMS','ICBP','INCO',
        'INDF','INDY','INKP','ISAT','ITMG','KLBF','MAPI','MBMA','MDKA','MEDC','MIKA','MLPT', 'MSKY','PANI','PGAS','PGEO',
        'PTBA','PTRO','SMGR','SRTG','TLKM','TPIA','TSPC','UNTR','UNVR'],
    'default_lot_size' => 100,
    'tick_size' => [IdxTicks::class, 'tickFor'], // according to the IDX variable tick ladder.
    'chunk_size' => 200, // number of rows to insert per batch
];
