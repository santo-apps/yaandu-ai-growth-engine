<?php

namespace App\Agents;

use Throwable;

final readonly class AgentFailure
{
    public function __construct(public string $code, public string $message, public string $correlationId) {}

    public static function from(Throwable $error, string $correlationId): self
    {
        $message = strtolower($error->getMessage());
        $code = match (true) {
            $error instanceof \Illuminate\Validation\ValidationException => 'AGENT_OUTPUT_INVALID',
            str_contains($message, 'outside the required schema range') => 'AGENT_OUTPUT_INVALID',
            str_contains($message, 'no approved active prompt') || str_contains($message, 'approved websiteintelligenceagent prompt') => 'PROMPT_MISSING',
            str_contains($message, 'no ai model configured') || str_contains($message, 'configured ai provider') => 'MODEL_ROUTE_MISSING',
            str_contains(strtolower($error::class), 'timeout') || str_contains($message, 'timeout') => 'PROVIDER_TIMEOUT',
            str_contains(strtolower($error::class), 'provider') || str_contains($message, 'provider request failed') || str_contains($message, 'credentials are not configured') => self::providerFailureCode($error),
            default => 'AGENT_EXECUTION_FAILED',
        };

        return new self($code, match ($code) {
            'AGENT_OUTPUT_INVALID' => 'The agent returned data that did not meet its required schema.',
            'PROMPT_MISSING' => 'An approved active prompt is not configured for this agent.',
            'MODEL_ROUTE_MISSING' => 'A supported model route is not configured for this task.',
            'PROVIDER_AUTH' => 'The configured AI provider could not authenticate this request.',
            'PROVIDER_RATE_LIMIT' => 'The configured AI provider rate limit was reached.',
            'PROVIDER_TIMEOUT' => 'The AI provider request timed out.',
            'PROVIDER_SCHEMA' => 'The AI provider response did not match the required structured format.',
            default => 'The agent could not complete this request.',
        }, $correlationId);
    }

    private static function providerFailureCode(Throwable $error): string
    {
        $category = (new \App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy())->classifyProvider($error);
        return $category === 'UNKNOWN' ? 'AI_PROVIDER_UNAVAILABLE' : $category;
    }
}
