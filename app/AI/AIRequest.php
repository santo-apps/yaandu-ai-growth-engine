<?php

namespace App\AI;

final readonly class AIRequest
{
    public function __construct(
        public string $task,
        public string $systemInstruction,
        public array $evidence,
        public array $outputSchema,
        public int $maxOutputTokens = 1200,
        public float $temperature = 0.2,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
