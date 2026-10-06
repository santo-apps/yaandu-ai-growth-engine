<?php

namespace App\Marketing;

final readonly class GenerateMarketingDraftCommand
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public ?string $contactId,
        public ?string $campaignId,
        public ?string $campaignObjective,
        public ?string $actorId,
        public string $idempotencyKey,
        public ?string $previousDraftId = null,
        public ?string $workflowId = null,
        public ?string $correlationId = null,
        public ?string $requestId = null,
    ) {}
}
