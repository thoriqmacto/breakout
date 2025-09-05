<?php

use App\Services\IdxTicks;

return [
    'seed_dir' => storage_path('app/historical'), // put your 30 CSVs here
    'index_symbols' => ['ADRO','ANTM'],
//    'index_symbols' => [
//        'ADRO','AKRA','AMMN','ANTM','ASII','BRIS','BRMS','BRPT','CPIN','EXCL','ICBP','INCO',
//        'INDF','INKP','ISAT','KLBF','MAPI','MBMA','MDKA','MEDC','PANI','PGAS','PGEO','PTBA',
//        'PTRO','SMGR','TLKM','TPIA','UNTR','UNVR'],
    'default_lot_size' => 100,
    'tick_size' => [IdxTicks::class, 'tickFor'], // according to the IDX variable tick ladder.
    'chunk_size' => 200, // number of rows to insert per batch
];
