<?php

declare(strict_types=1);

return [
    'default_connection' => env('WEAVER_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'driver' => env('WEAVER_DRIVER', 'pdo_pgsql'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT'),
            'dbname' => env('DB_DATABASE', 'forge'),
            'user' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
        ],
    ],
    'debug' => env('WEAVER_DEBUG', false),
    'n1_detector' => env('WEAVER_N1_DETECTOR', false),
    'max_rows_safety_limit' => 100000,
    'mapper_directories' => [
        app_path('Mappers'),
    ],
    'cache' => [
        'enabled' => false,
        'store' => env('WEAVER_CACHE_STORE', 'default'),
        'default_ttl' => 3600,
    ],
];
