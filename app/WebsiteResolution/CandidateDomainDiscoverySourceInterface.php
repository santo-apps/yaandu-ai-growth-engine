<?php

namespace App\WebsiteResolution;

interface CandidateDomainDiscoverySourceInterface
{
    public function name(): string;

    public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch;
}
