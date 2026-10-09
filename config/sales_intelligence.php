<?php

return [
    'default_mode' => env('SALES_INTELLIGENCE_MODE', 'human_assisted'),
    // Autonomous behavior stays unavailable unless a future, separately reviewed release enables it.
    'experimental_autonomous_enabled' => (bool) env('SALES_INTELLIGENCE_EXPERIMENTAL_AUTONOMOUS', false),
];
