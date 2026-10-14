<?php

namespace App\LeadDiscovery;

final readonly class DiscoveryPage
{
    /** @param list<array<string, mixed>> $candidates */
    public function __construct(
        public array $candidates,
        public ?string $nextCursor,
        public bool $exhausted,
        public ?string $providerRequestId,
        public array $providerMetadata = [],
    ) {}
}
