<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY', 'fallback-jwt-secret')),

    'algo' => env('JWT_ALGO', 'HS256'),

    'ttl' => (int) env('JWT_TTL', 3600),

    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 1209600),

    'leeway' => (int) env('JWT_LEEWAY', 0),
];
