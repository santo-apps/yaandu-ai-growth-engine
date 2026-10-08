<?php

namespace App\WebsiteResolution;

use Throwable;

/** Uses the documented Wikidata Action API search and P856 claims; each returned URL is still only a candidate. */
final class WikidataCandidateDomainDiscoverySource implements CandidateDomainDiscoverySourceInterface
{
    public function __construct(private readonly WikidataBusinessSearchClient $client, private readonly BusinessIdentityNormalizer $normalizer) {}

    public function name(): string { return 'wikidata_open_search'; }

    public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch
    {
        $queries = app(CandidateDomainQueryPlanner::class)->plan($identity);
        $entityMap = [];
        $failures = [];
        $cacheHitsBefore = $this->client->cacheHits();
        $requestsBefore = $this->client->requestCalls();
        $boundedQueries = array_slice($queries, 0, $context->maxQueries);
        $perQueryLimit = min($context->maxResultsPerQuery, max(1, (int) ceil($context->maxRawResultsPerSource / max(1, count($boundedQueries)))));
        foreach ($boundedQueries as $query) {
            if (hrtime(true) / 1_000_000 >= $context->deadlineMilliseconds) {
                $failures[] = 'query_budget_exhausted';
                break;
            }
            try {
                foreach ($this->client->search($query, $perQueryLimit) as $entity) {
                    $id = $entity['id'];
                    if (! isset($entityMap[$id]) || $entity['rank'] < $entityMap[$id]['rank']) $entityMap[$id] = $entity + ['query' => $query];
                }
            } catch (Throwable) {
                $failures[] = 'query_unavailable';
            }
        }
        $claims = [];
        try { $claims = $this->client->officialWebsites(array_keys($entityMap)); }
        catch (Throwable) { $failures[] = 'claims_unavailable'; }

        $results = [];
        foreach ($entityMap as $entityId => $entity) {
            $matchesName = $this->normalizer->name($identity->businessName) !== ''
                && $this->normalizer->name($identity->businessName) === $this->normalizer->name($entity['label']);
            $entityUrl = 'https://www.wikidata.org/wiki/'.$entityId;
            $websites = array_slice((array) ($claims[$entityId] ?? []), 0, 3);
            if ($websites === []) {
                $results[] = ['query' => $entity['query'], 'result_url' => $entityUrl, 'target_url' => null,
                    'title' => $entity['label'], 'snippet' => $entity['description'], 'rank' => $entity['rank'],
                    'source_reference' => $entityId, 'result_type' => 'UNKNOWN', 'metadata' => ['has_website_claim' => false], 'evidence' => []];
                continue;
            }
            foreach ($websites as $url) {
                $results[] = ['query' => $entity['query'], 'result_url' => $entityUrl, 'target_url' => $url,
                    'title' => $entity['label'], 'snippet' => $entity['description'], 'rank' => $entity['rank'],
                    'source_reference' => $entityId, 'result_type' => 'POSSIBLE_OFFICIAL_SITE',
                    'metadata' => ['claim' => 'P856', 'entity_id' => $entityId, 'entity_label' => $entity['label']],
                    'evidence' => [['signal' => $matchesName ? 'wikidata_name_match' : 'wikidata_entity_search',
                        'polarity' => 'neutral', 'points' => 0,
                        'summary' => $matchesName ? 'Wikidata search returned an exact normalized business-name entity with a website claim.' : 'Wikidata search returned an entity with a website claim; entity identity still requires verification.',
                        'details' => ['entity_id' => $entityId, 'entity_label' => $entity['label'], 'description' => $entity['description'], 'claim' => 'P856']]]];
            }
        }

        return new CandidateDiscoveryBatch($results, $boundedQueries, ['entities' => count($entityMap), 'results' => count($results),
            'cache_hits' => max(0, $this->client->cacheHits() - $cacheHitsBefore),
            'provider_calls' => max(0, $this->client->requestCalls() - $requestsBefore)], array_values(array_unique($failures)));
    }
}
