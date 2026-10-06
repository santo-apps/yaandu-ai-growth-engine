<?php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'redis' => ['driver' => 'redis', 'connection' => env('REDIS_QUEUE_CONNECTION', 'default'), 'queue' => env('REDIS_QUEUE', 'default'), 'retry_after' => 420, 'block_for' => 5, 'after_commit' => true],
    ],
    'failed' => ['driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'), 'database' => env('DB_CONNECTION', 'pgsql'), 'table' => 'failed_jobs'],
    'batching' => ['database' => env('DB_CONNECTION', 'pgsql'), 'table' => 'job_batches'],
];
