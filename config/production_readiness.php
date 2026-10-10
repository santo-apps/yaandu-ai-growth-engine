<?php

return [
    'debug_enabled' => (bool) env('APP_DEBUG', false),
    'tenant_id' => env('PRODUCTION_READINESS_TENANT_ID'),
    'backup_verified' => (bool) env('PRODUCTION_BACKUP_VERIFIED', false),
    'monitoring_configured' => (bool) env('PRODUCTION_MONITORING_CONFIGURED', false),
    'scheduler_heartbeat_configured' => (bool) env('SCHEDULE_HEARTBEAT_CONFIGURED', false),
    // Operator attests the selected production storage backend has been verified.
    // Local storage is also probed at runtime by `production:readiness`.
    'storage_runtime_verified' => (bool) env('PRODUCTION_STORAGE_RUNTIME_VERIFIED', false),
    'trusted_edge_verified' => (bool) env('PRODUCTION_TRUSTED_EDGE_VERIFIED', false),
];
