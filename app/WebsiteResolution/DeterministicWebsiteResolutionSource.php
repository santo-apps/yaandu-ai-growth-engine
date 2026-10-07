<?php

namespace App\WebsiteResolution;

/** No-network source for acceptance fixtures; never registered outside PHPUnit. */
final class DeterministicWebsiteResolutionSource implements WebsiteResolutionSourceInterface
{
    public function name(): string { return 'deterministic'; }
    public function find(array $identity, array $knownCandidates = []): array
    {
        $key = (new WebsiteIdentitySnapshotFactory())->normalizeName((string) ($identity['business_name'] ?? ''));
        return array_slice((array) config('website_resolution.deterministic_fixtures.'.$key, []), 0, (int) config('website_resolution.max_candidate_domains_per_business', 5));
    }
}
