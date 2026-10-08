<?php

$genericEmailDomains = explode(',', env('CANDIDATE_DISCOVERY_GENERIC_EMAIL_DOMAINS', 'gmail.com,googlemail.com,outlook.com,hotmail.com,live.com,yahoo.com,yahoo.co.in,icloud.com,aol.com,proton.me,protonmail.com'));

return [
    'enabled' => (bool) env('CANDIDATE_DISCOVERY_ENABLED', true),
    'queue' => env('CANDIDATE_DISCOVERY_QUEUE', 'candidate-discovery'),
    'wikidata_search_enabled' => (bool) env('CANDIDATE_DISCOVERY_WIKIDATA_ENABLED', false),
    'live_sources_in_tests' => (bool) env('CANDIDATE_DISCOVERY_LIVE_SOURCES_IN_TESTS', false),
    'max_queries_per_business' => min(5, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_QUERIES', 5))),
    'max_sources_per_business' => min(8, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_SOURCES', 5))),
    'max_sources_per_query' => min(5, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_SOURCES_PER_QUERY', 2))),
    'max_results_per_source' => min(10, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_RESULTS_PER_SOURCE', 10))),
    'max_candidate_domains_per_business' => min(10, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_CANDIDATES', 10))),
    'max_verification_candidates_per_business' => min(5, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_VERIFICATIONS', 5))),
    'max_bulk_businesses' => min(25, max(1, (int) env('CANDIDATE_DISCOVERY_MAX_BULK', 25))),
    'max_runtime_seconds' => min(55, max(5, (int) env('CANDIDATE_DISCOVERY_MAX_RUNTIME_SECONDS', 45))),
    'verification_candidates_per_business' => min(5, max(1, (int) env('CANDIDATE_DISCOVERY_VERIFICATION_CANDIDATES', 5))),
    'minimum_name_length' => min(20, max(2, (int) env('CANDIDATE_DISCOVERY_MIN_NAME_LENGTH', 3))),
    'wikidata_cache_seconds' => max(300, (int) env('CANDIDATE_DISCOVERY_WIKIDATA_CACHE_SECONDS', 604800)),
    'wikidata_max_requests_per_minute' => min(60, max(1, (int) env('CANDIDATE_DISCOVERY_WIKIDATA_RATE_PER_MINUTE', 30))),
    'generic_email_domains' => array_values(array_unique(array_map('mb_strtolower', array_filter(array_map('trim', $genericEmailDomains))))),
    'deterministic_fixtures' => [],
];
