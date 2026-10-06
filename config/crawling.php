<?php

return [
    'playwright' => [
        'enabled' => env('CRAWLER_PLAYWRIGHT_ENABLED', false),
        'node_binary' => env('NODE_BINARY', 'node'),
        'browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH'),
        'timeout_seconds' => 45,
    ],
    'screenshots' => [
        'enabled' => env('CRAWLER_SCREENSHOTS_ENABLED', true),
        'viewport' => '1365x900',
        'max_bytes' => 5_000_000,
    ],
    'max_response_bytes' => (int) env('CRAWLER_MAX_RESPONSE_BYTES', 5_000_000),
    'request_timeout_seconds' => (int) env('CRAWLER_REQUEST_TIMEOUT_SECONDS', 15),
    'max_fetch_attempts' => (int) env('CRAWLER_MAX_FETCH_ATTEMPTS', 100),
    'max_links_per_page' => (int) env('CRAWLER_MAX_LINKS_PER_PAGE', 200),
    'max_duration_seconds' => (int) env('CRAWLER_MAX_DURATION_SECONDS', 180),
];
