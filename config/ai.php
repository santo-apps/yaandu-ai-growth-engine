<?php

return [
    'local_acceptance' => ['enabled' => env('AI_DETERMINISTIC_ENABLED', false)],
    'tasks' => [
        'website_visual_analysis' => ['provider' => env('AI_VISUAL_PROVIDER', 'openai'), 'model' => env('AI_VISUAL_MODEL', 'gpt-4.1-mini')],
        'website_reasoning' => ['provider' => env('AI_REASONING_PROVIDER', 'anthropic'), 'model' => env('AI_REASONING_MODEL', 'claude-3-7-sonnet-latest')],
        'lead_classification' => ['provider' => env('AI_CLASSIFICATION_PROVIDER', 'gemini'), 'model' => env('AI_CLASSIFICATION_MODEL', 'gemini-2.5-flash')],
        'sales_reasoning' => ['provider' => env('AI_SALES_PROVIDER', 'anthropic'), 'model' => env('AI_SALES_MODEL', 'claude-3-7-sonnet-latest')],
        'content_generation' => ['provider' => env('AI_CONTENT_PROVIDER', 'openai'), 'model' => env('AI_CONTENT_MODEL', 'gpt-4.1-mini')],
        'structured_extraction' => ['provider' => env('AI_EXTRACTION_PROVIDER', 'gemini'), 'model' => env('AI_EXTRACTION_MODEL', 'gemini-2.5-flash')],
        'proposal_generation' => ['provider' => env('AI_PROPOSAL_PROVIDER', 'anthropic'), 'model' => env('AI_PROPOSAL_MODEL', 'claude-3-7-sonnet-latest'), 'parameters' => ['max_output_tokens' => 3000, 'temperature' => 0.15]],
    ],
    'providers' => [
        // Only registered when APP_ENV is local/testing and the explicit acceptance flag is enabled.
        'deterministic' => ['key' => null],
        'openai' => ['endpoint' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), 'key' => env('OPENAI_API_KEY')],
        'anthropic' => ['endpoint' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'), 'key' => env('ANTHROPIC_API_KEY')],
        'gemini' => ['endpoint' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), 'key' => env('GEMINI_API_KEY')],
    ],
    'timeouts' => ['connect' => 5, 'request' => 45],
    'retries' => ['attempts' => 2],
    // Optional provider/model-specific rates per 1,000 tokens. Keep empty until verified rates are configured.
    'model_pricing_per_1k' => [],
];
