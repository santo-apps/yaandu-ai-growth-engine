<?php

namespace App\Agents;

final readonly class AgentContext
{
    public function __construct(
        public string $tenantId,
        public string $runId,
        public ?string $actorId,
        public string $correlationId,
        public array $configuration = [],
    ) {}
}
