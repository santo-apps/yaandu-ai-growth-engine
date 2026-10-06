<?php

return [
    'intent_confidence_threshold' => (float) env('SALES_INTENT_CONFIDENCE_THRESHOLD', 0.72),
    'follow_up_confidence_threshold' => (float) env('FOLLOW_UP_CONFIDENCE_THRESHOLD', 0.85),
];
