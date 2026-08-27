<?php

return [
    'bearer' => env('STOCKBIT_BEARER', ''),
    'base_url' => env('STOCKBIT_EXODUS_BASE', 'https://exodus.stockbit.com'),

    'defaults' => [
        'transaction_type' => env('SB_TRANSACTION_TYPE', 'TRANSACTION_TYPE_NET'),
        'market_board' => env('SB_MARKET_BOARD', 'MARKET_BOARD_REGULER'),
        'investor_type' => env('SB_INVESTOR_TYPE', 'INVESTOR_TYPE_ALL'),
        'limit' => (int) env('SB_LIMIT', 25),
    ],

    'historical' => [
        'period' => env('SB_HISTORICAL_PERIOD', 'HS_PERIOD_DAILY'),
        'limit' => env('SB_HISTORICAL_LIMIT'),
        'page' => env('SB_HISTORICAL_PAGE'),
    ],

    'save_disk' => env('SB_SAVE_DISK', 'local'),
    'save_dir' => env('SB_SAVE_DIR', 'broker_summary'),

    'strategy' => [
        // How far back the strategy services may reach for the broker window
        // that describes a date, in days.
        //
        // A broker summary is an aggregate over a range, so "the window as of
        // 26 August" is the most recent one that ended on or before it -- but
        // without a bound that reaches back forever, and a symbol last imported
        // in March would still present March's flow as September's broker
        // picture. Set to null to disable the bound.
        'max_window_staleness_days' => env('SB_MAX_WINDOW_STALENESS_DAYS', 7),
    ],

    'watchlist' => [
        'id' => env('SB_WATCHLIST_ID', 808507),
        'query' => [
            'page' => env('SB_WATCHLIST_PAGE', 1),
            'limit' => env('SB_WATCHLIST_LIMIT', 500),
            'nochart' => env('SB_WATCHLIST_NOCHART', 1),
            'setfincol' => env('SB_WATCHLIST_SETFINCOL', 1),
        ],
    ],
];
