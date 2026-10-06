<?php

namespace App\Agents;

use Throwable;

final readonly class AgentFailure
{
    public function __construct(public string $code, public string $message, public string $correlationId) {}

    public static function from(Throwable $error, string $correlationId): self
    {
        $code = match (true) {
            $error instanceof \Illuminate\Validation\ValidationException => 'AGENT_OUTPUT_INVALID',
            str_contains(strtolower($error::class), 'timeout') => 'AGENT_TIMEOUT',
            str_contains(strtolower($error::class), 'provider') => 'AI_PROVIDER_UNAVAILABLE',
            default => 'AGENT_EXECUTION_FAILED',
        };

        return new self($code, match ($code) {
            'AGENT_OUTPUT_INVALID' => 'The agent returned data that did not meet its required schema.',
            'AGENT_TIMEOUT' => 'The agent exceeded its execution time limit.',
            'AI_PROVIDER_UNAVAILABLE' => 'The AI service is temporarily unavailable.',
            default => 'The agent could not complete this request.',
        }, $correlationId);
    }
}
