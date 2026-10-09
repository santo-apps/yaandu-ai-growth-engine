<?php

return [
    'max_import_rows' => (int) env('PILOT_MAX_IMPORT_ROWS', 25),
    'allow_simulated_fixtures' => (bool) env('PILOT_ALLOW_SIMULATED_FIXTURES', false),
];
