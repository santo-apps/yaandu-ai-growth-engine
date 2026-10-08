<?php

namespace App\WebsiteResolution;

final class LegacyWebsiteResolutionDiscoveryAdapter implements CandidateDomainDiscoverySourceInterface
{
    public function __construct(private readonly WebsiteResolutionSourceInterface $source) {}

    public function name(): string { return $this->source->name(); }

    public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch
    {
        $found = $this->source->find($identity->toArray());
        $results = [];
        foreach (array_slice($found, 0, $context->maxResultsPerQuery) as $index => $item) {
            $sourceReference = (string) ($item['source_reference'] ?? '');
            $resultUrl = filter_var($sourceReference, FILTER_VALIDATE_URL) ? $sourceReference : null;
            $results[] = ['query' => 'existing_public_identity_evidence', 'result_url' => $resultUrl,
                'target_url' => isset($item['url']) ? (string) $item['url'] : null,
                'title' => isset($item['candidate_type']) ? mb_substr((string) $item['candidate_type'], 0, 500) : null,
                'snippet' => isset($item['evidence'][0]['summary']) ? mb_substr((string) $item['evidence'][0]['summary'], 0, 1000) : null,
                'rank' => (int) ($item['search_rank'] ?? ($index + 1)), 'source_reference' => $sourceReference ?: null,
                'result_type' => isset($item['candidate_type']) ? strtoupper((string) $item['candidate_type']) : 'UNKNOWN',
                'metadata' => array_intersect_key($item, array_flip(['candidate_type', 'search_rank', 'matching_fields', 'index_provenance'])),
                'evidence' => (array) ($item['evidence'] ?? [])];
        }

        return new CandidateDiscoveryBatch($results, ['existing_public_identity_evidence'], ['result_count' => count($results)]);
    }
}
