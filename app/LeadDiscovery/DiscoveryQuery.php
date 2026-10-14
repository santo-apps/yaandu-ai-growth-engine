<?php

namespace App\LeadDiscovery;

final readonly class DiscoveryQuery
{
    public function __construct(
        public string $campaignId,
        public string $tenantId,
        public string $industry,
        public string $country,
        public ?string $region,
        public ?string $city,
        public string $queryKey,
        public string $keywords,
        public int $limit,
        public ?string $cursor = null,
    ) {}
}
