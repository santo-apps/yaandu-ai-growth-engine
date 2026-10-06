<?php

return [
    'outreach_score_threshold' => (int) env('ORCHESTRATION_OUTREACH_SCORE_THRESHOLD', 70),
    // System minimums; no tenant override can lower these safety requirements.
    'confidence_minimums' => [
        'GENERATE_MARKETING_DRAFT' => 0.55,
        'GENERATE_FOLLOW_UP' => 0.85,
        'RUN_SALES_ANALYSIS' => 0.70,
        'GENERATE_PROPOSAL' => 0.75,
    ],
];
