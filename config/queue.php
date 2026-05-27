<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Default to Redis. Update processing is dispatched onto a Redis-backed queue
    | and managed by Laravel Horizon (see config/horizon.php). The value is env-
    | driven so tests can override it to `sync`.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | We keep the standard connections. `redis` is the production driver Horizon
    | supervises; `sync` runs jobs inline (used by the test suite so update jobs
    | execute immediately and assertions can inspect the result); `database` is a
    | conventional fallback. The `failed` table records jobs that exhaust retries.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            // Use a dedicated Redis connection profile named `default` from
            // config/database.php's redis block.
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // retry_after MUST exceed the job's max processing time so a job is
            // not retried while still running. Our update job is fast, but we
            // leave generous headroom.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            // Dispatch jobs only after the surrounding DB transaction commits.
            // This avoids a worker picking up a job before the row it depends on
            // is visible.
            'after_commit' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | Failed jobs are recorded so the failed() hook on UpdateFlightJob can mark
    | the idempotency record failed and operators can inspect/retry.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
