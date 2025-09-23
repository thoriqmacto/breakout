<?php

return [
    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))
    ))),

    'guard' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_GUARDS', ''))
    ))),

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [],
];
