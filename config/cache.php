<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | Default to Redis. This matters specifically because the IdempotencyManager
    | uses Cache::lock() for the per-key Redis lock, and the Redis store provides
    | the atomic lock primitive that guarantee depends on. Tests override this to
    | `array` (which also supports locks in-process).
    |
    */

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'redis' => [
            'driver' => 'redis',
            // Use the dedicated `cache` Redis connection so lock keys live apart
            // from queue data; locks are acquired against `lock_connection`.
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('CACHE_PREFIX', 'flights_cache_'),

];
