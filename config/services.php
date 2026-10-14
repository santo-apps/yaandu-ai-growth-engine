<?php

return [
    'brave_search' => [
        'key' => env('BRAVE_SEARCH_API_KEY'),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-6'),
    ],
];
