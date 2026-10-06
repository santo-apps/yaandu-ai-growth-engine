<?php

namespace App\Proposals;

final readonly class GenerateProposalCommand
{
    public function __construct(
        public string $tenantId,
        public string $proposalId,
        public ?string $actorId,
        public string $idempotencyKey,
        public ?string $requestId = null,
    ) {}
}
