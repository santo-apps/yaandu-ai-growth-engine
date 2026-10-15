<?php

return [
    'brave_search' => [
        'key' => env('BRAVE_SEARCH_API_KEY'),
    ],
    'data_gov_in' => [
        'api_key' => env('DATA_GOV_IN_API_KEY'),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-6'),
    ],
];
