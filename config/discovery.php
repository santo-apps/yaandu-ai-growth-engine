<?php

return [
    'max_candidates_per_run' => (int) env('DISCOVERY_MAX_CANDIDATES_PER_RUN', 100),
    'max_csv_rows' => (int) env('DISCOVERY_MAX_CSV_ROWS', 500),
    'max_websites_verified_per_run' => (int) env('DISCOVERY_MAX_VERIFICATIONS_PER_RUN', 100),
    'max_pages_per_domain' => (int) env('DISCOVERY_MAX_PAGES_PER_DOMAIN', 10),
    'max_browser_renders_per_run' => (int) env('DISCOVERY_MAX_BROWSER_RENDERS_PER_RUN', 0),
    'max_ai_analyses_per_run' => (int) env('DISCOVERY_MAX_AI_ANALYSES_PER_RUN', 25),
    'max_runtime_seconds' => (int) env('DISCOVERY_MAX_RUNTIME_SECONDS', 600),
    'allow_deterministic' => env('DISCOVERY_ALLOW_DETERMINISTIC', false),
];
