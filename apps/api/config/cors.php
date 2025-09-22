<?php

return [
    'allowed_origins' => array_values(array_unique(array_map(
        static fn (string $origin): string => rtrim($origin, '/'),
        array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))
        ))
    ))),

    'allowed_headers' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_HEADERS', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept, Origin'))
    )))),

    'allowed_methods' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'))
    )))),

    'exposed_headers' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_EXPOSED_HEADERS', ''))
    )))),

    'max_age' => (int) env('CORS_MAX_AGE', 86400),
];
