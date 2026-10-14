<?php

namespace App\Outreach;

/** Internal composition representation. Segments retain provenance before rendering to the public payload. */
final readonly class OutreachDraftComposition
{
    /** @param array<string, mixed> $draft @param list<array{field: string, type: string, finding_id?: string, text: string, separator_before: string}> $segments @param list<string> $findingIds */
    public function __construct(
        public array $draft,
        public array $segments,
        public array $findingIds,
        public string $serviceId,
        public string $recommendedChannel,
        public string $requestedWritingStyle = 'consultative',
    ) {}
}
