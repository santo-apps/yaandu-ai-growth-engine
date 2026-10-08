<?php

namespace App\WebsiteResolution;

final readonly class CandidateDiscoveryContext
{
    public function __construct(
        public string $tenantId,
        public string $resolutionId,
        public int $maxQueries,
        public int $maxResultsPerQuery,
        public int $maxRawResultsPerSource,
        public int $deadlineMilliseconds,
    ) {}
}
