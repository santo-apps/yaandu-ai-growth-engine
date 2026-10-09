<?php

return [
    'name' => env('APP_NAME', 'Yaandu AI Growth Engine'), 'env' => env('APP_ENV', 'production'),
    // Never allow a misconfigured APP_DEBUG to expose exception details in production.
    'debug' => env('APP_ENV') === 'production' ? false : (bool) env('APP_DEBUG', false), 'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC', 'key' => env('APP_KEY'), 'previous_keys' => array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    'maintenance' => ['driver' => env('APP_MAINTENANCE_DRIVER', 'file'), 'store' => env('APP_MAINTENANCE_STORE', 'database')],
];
