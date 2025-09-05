<?php
return [
    'seed_dir' => storage_path('app/historical'), // put your 30 CSVs here
    'index_symbols' => ['ADRO','AKRA','AMMN','ANTM','ASII','BRIS','BRMS','BRPT','CPIN','EXCL','ICBP','INCO',
        'INDF','INKP','ISAT','KLBF','MAPI','MBMA','MDKA','MEDC','PANI','PGAS','PGEO','PTBA','PTRO','SMGR',
        'TLKM','TPIA','UNTR','UNVR'],
    'default_lot_size' => 100,
    'tick_size' => 1.0,
    'chunk_size' => 200, // number of rows to insert per batch
];
