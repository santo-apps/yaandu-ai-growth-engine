<?php

return [
    'max_import_rows' => (int) env('PILOT_MAX_IMPORT_ROWS', 100),
    'allow_simulated_fixtures' => (bool) env('PILOT_ALLOW_SIMULATED_FIXTURES', false),
];
