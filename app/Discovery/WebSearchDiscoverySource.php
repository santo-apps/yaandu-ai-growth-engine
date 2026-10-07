<?php

namespace App\Discovery;

final class WebSearchDiscoverySource implements DiscoverySourceInterface
{
    public function __construct(private readonly SearchEngineInterface $engine) {}
    public function name(): string { return 'web_search'; }
    public function capabilities(): array { return ['business_web_search']; }
    public function search(DiscoveryQuery $query): array
    {
        $location = trim((string) ($query->criteria['city'] ?? $query->criteria['location'] ?? ''));
        $category = trim((string) ($query->criteria['business_category'] ?? $query->criteria['industry'] ?? 'business'));
        $phrases = $location !== '' ? [$category.' '.$location] : [$category];
        foreach (array_slice((array) ($query->criteria['keywords'] ?? []), 0, 5) as $keyword) {
            $phrase = trim((string) $keyword.' '.$location);
            if ($phrase !== '' && ! in_array($phrase, $phrases, true)) $phrases[] = $phrase;
        }
        $results = $this->engine->search(new SearchQuery($phrases, min($query->limit, 25)));
        return array_map(static fn (array $result) => [
            'name' => $result['title'] ?? null, 'website' => $result['url'] ?? null,
            'industry' => $result['display_domain'] ?? null, 'source' => 'web_search',
            'source_reference' => 'web-search:'.hash('sha256', (string) ($result['url'] ?? '').':'.(string) ($result['query'] ?? '')),
            'source_metadata' => ['query' => $result['query'] ?? null, 'rank' => $result['rank'] ?? null,
                'snippet' => mb_substr((string) ($result['snippet'] ?? ''), 0, 500), 'claim_status' => 'unverified_search_snippet'],
        ], array_slice($results->results, 0, $query->limit));
    }
}
