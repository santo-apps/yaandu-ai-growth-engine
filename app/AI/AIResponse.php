<?php

namespace App\AI;

final readonly class AIResponse
{
    public function __construct(
        public array $data,
        public string $provider,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $latencyMs = null,
    ) {}
}
