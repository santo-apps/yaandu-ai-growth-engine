<?php

return [
    'default_provider' => env('OUTBOUND_DEFAULT_PROVIDER', 'fake'),
    'enabled_providers' => array_filter(explode(',', env('OUTBOUND_ENABLED_PROVIDERS', 'fake'))),
    'webhook_clock_skew_seconds' => 300,
    'idempotency_key_max_length' => 128,
    'retry' => ['attempts' => 3, 'backoff_seconds' => [30, 300, 1800]],
];
