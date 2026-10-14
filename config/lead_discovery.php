<?php

return [
    'enabled' => (bool) env('LEAD_DISCOVERY_ENABLED', true),
    'auto_promotion' => false,
    'max_csv_bytes' => (int) env('LEAD_DISCOVERY_MAX_CSV_BYTES', 2_097_152),
    'max_rows_per_import' => (int) env('LEAD_DISCOVERY_MAX_ROWS_PER_IMPORT', 100),
    'max_source_requests_per_run' => (int) env('LEAD_DISCOVERY_MAX_SOURCE_REQUESTS', 100),
    'max_candidates_per_run' => (int) env('LEAD_DISCOVERY_MAX_CANDIDATES', 100),
    'max_candidate_processing' => (int) env('LEAD_DISCOVERY_MAX_PROCESSING', 100),
    'max_enrichment_per_run' => (int) env('LEAD_DISCOVERY_MAX_ENRICHMENT', 100),
    'max_runtime_seconds' => (int) env('LEAD_DISCOVERY_MAX_RUNTIME_SECONDS', 600),
    'max_http_requests_per_candidate' => (int) env('LEAD_DISCOVERY_MAX_HTTP_PER_CANDIDATE', 3),
    'max_ai_calls_per_run' => 0,
    'max_provider_spend_minor' => 0,
    'validation_queue' => env('LEAD_DISCOVERY_VALIDATION_QUEUE', 'lead-validation'),
    'promotion_queue' => env('LEAD_DISCOVERY_PROMOTION_QUEUE', 'website-intelligence'),
    'target_worker_concurrency' => (int) env('LEAD_DISCOVERY_WORKERS', 2),
];
