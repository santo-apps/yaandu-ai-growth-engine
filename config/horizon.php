<?php

return [
    'domain' => env('HORIZON_DOMAIN'), 'path' => env('HORIZON_PATH', 'horizon'), 'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'yaandu_horizon:'), 'middleware' => ['web', 'auth'],
    'waits' => ['redis:default' => 60, 'redis:candidate-discovery' => 30, 'redis:intake' => 30, 'redis:crawl' => 30, 'redis:intelligence' => 30, 'redis:campaigns' => 60, 'redis:outbound' => 30, 'redis:conversations' => 45, 'redis:workflow' => 30],
    'environments' => [
        'production' => [
            'supervisor-default' => ['connection' => 'redis', 'queue' => ['default'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 5, 'tries' => 3, 'timeout' => 240],
            'supervisor-intake' => ['connection' => 'redis', 'queue' => ['intake'], 'balance' => 'simple', 'processes' => min(4, max(1, (int) env('PILOT_IMPORT_MAX_WORKERS', 2))), 'tries' => 3, 'timeout' => 60],
            'supervisor-discovery' => ['connection' => 'redis', 'queue' => ['discovery'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 3, 'tries' => 3, 'timeout' => 240],
            'supervisor-candidate-discovery' => ['connection' => 'redis', 'queue' => ['candidate-discovery'], 'balance' => 'simple', 'processes' => min(2, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_WORKERS', 1))), 'tries' => 1, 'timeout' => 65],
            'supervisor-crawl' => ['connection' => 'redis', 'queue' => ['crawl'], 'balance' => 'simple', 'processes' => 1, 'tries' => 2, 'timeout' => 390],
            'supervisor-web-index' => ['connection' => 'redis', 'queue' => ['web-index'], 'balance' => 'simple', 'processes' => min(2, max(1, (int) env('WEBSITE_INDEX_INGESTION_MAX_WORKERS', 1))), 'tries' => 10, 'timeout' => 390],
            'supervisor-intelligence' => ['connection' => 'redis', 'queue' => ['intelligence'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4, 'tries' => 3, 'timeout' => 240],
            'supervisor-scoring' => ['connection' => 'redis', 'queue' => ['scoring'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4, 'tries' => 3, 'timeout' => 240],
            'supervisor-campaigns' => ['connection' => 'redis', 'queue' => ['campaigns'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 3, 'tries' => 3, 'timeout' => 120],
            'supervisor-outbound' => ['connection' => 'redis', 'queue' => ['outbound'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4, 'tries' => 5, 'timeout' => 90],
            'supervisor-conversations' => ['connection' => 'redis', 'queue' => ['conversations'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 3, 'tries' => 3, 'timeout' => 120],
            'supervisor-workflow' => ['connection' => 'redis', 'queue' => ['workflow'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 3, 'tries' => 3, 'timeout' => 120],
        ],
        'local' => [
            'supervisor-local' => ['connection' => 'redis', 'queue' => ['default', 'discovery', 'intake', 'crawl', 'intelligence', 'scoring', 'campaigns', 'outbound', 'conversations', 'workflow'], 'balance' => 'simple', 'processes' => 2, 'tries' => 1000, 'timeout' => 390],
            'supervisor-candidate-discovery' => ['connection' => 'redis', 'queue' => ['candidate-discovery'], 'balance' => 'simple', 'processes' => 1, 'tries' => 1, 'timeout' => 65],
            'supervisor-web-index' => ['connection' => 'redis', 'queue' => ['web-index'], 'balance' => 'simple', 'processes' => 1, 'tries' => 10, 'timeout' => 390],
        ],
    ],
];
