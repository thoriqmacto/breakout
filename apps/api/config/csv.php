<?php

use App\Services\IdxTicks;

return [
    'seed_dir' => database_path('seeders/data/historical'), // put your 30 CSVs here
    'default_lot_size' => 100,
    'tick_size' => [IdxTicks::class, 'tickFor'], // according to the IDX variable tick ladder.
    'chunk_size' => 200, // number of rows to insert per batch
];
