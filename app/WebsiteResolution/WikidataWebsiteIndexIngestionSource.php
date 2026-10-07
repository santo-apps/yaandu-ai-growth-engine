<?php

namespace App\WebsiteResolution;

use App\Discovery\DomainNormalizer;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WikidataWebsiteIndexIngestionSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['entities_seen' => 0, 'business_like_entities' => 0, 'p856_urls' => 0, 'normalized_urls' => 0,
        'unique_usable_domains' => 0, 'current_corpus_duplicates' => 0, 'duplicate_claim_domains' => 0,
        'url_normalization_rejections' => 0, 'directory_or_social_skipped' => 0, 'documents_indexed' => 0, 'duplicates' => 0, 'source_failures' => 0,
        'entity_budget_exhausted' => 0, 'time_budget_exhausted' => 0];

    public function __construct(
        private readonly WikidataClient $wikidata,
        private readonly PublicWebsiteDocumentFetcher $fetcher,
        private readonly DomainNormalizer $domains,
        private readonly DirectoryDomainClassifier $directories,
    ) {}

    public function name(): string { return 'wikidata_linked_websites'; }
    public function metrics(): array { return $this->counts; }

    /** Inspect P856 seeds from already linked public OSM entities; never fetches a website or writes a document. */
    public function inspectSeeds(int $limit = 25): array
    {
        $rows = DB::table('discovery_candidates as c')
            ->join('discovery_candidate_sources as s', function ($join): void { $join->on('s.candidate_id', '=', 'c.id')->on('s.tenant_id', '=', 'c.tenant_id'); })
            ->whereIn('s.source', ['openstreetmap', 'verified_open_discovery'])->orderBy('s.source_reference')
            ->limit(min(500, max(1, $limit * 10)))->get(['c.company_name', 'c.city', 'c.country', 'c.industry', 's.source_reference', 's.source_metadata']);
        $result = ['linked_rows' => $rows->count(), 'business_like_entities' => 0, 'entities_considered' => 0, 'p856_values' => 0,
            'normalized_urls' => 0, 'unique_usable_domains' => 0, 'current_corpus_duplicates' => 0, 'duplicate_claim_domains' => 0,
            'url_normalization_rejections' => 0, 'directory_or_social_skipped' => 0, 'source_failures' => 0];
        $seenEntities = []; $seenDomains = [];
        foreach ($rows as $row) {
            $meta = is_array($row->source_metadata) ? $row->source_metadata : (json_decode((string) $row->source_metadata, true) ?: []);
            $tags = (array) data_get($meta, 'tags', []);
            foreach (['wikidata' => true, 'operator:wikidata' => true, 'brand:wikidata' => true] as $tag => $_) {
                $entityId = (string) ($tags[$tag] ?? '');
                if (! preg_match('/^Q[1-9][0-9]{0,11}$/', $entityId) || isset($seenEntities[$entityId])) continue;
                if ($result['entities_considered'] >= min(100, max(1, $limit))) break 2;
                $seenEntities[$entityId] = true; $result['entities_considered']++;
                if (trim((string) $row->company_name) !== '' && (trim((string) $row->industry) !== '' || trim((string) $row->city) !== '')) $result['business_like_entities']++;
                try { $claims = $this->wikidata->officialWebsiteEntities($entityId); }
                catch (Throwable) { $result['source_failures']++; continue; }
                foreach ($claims as $claim) {
                    $result['p856_values']++;
                    try { $candidate = $this->domains->normalize((string) $claim['url']); }
                    catch (Throwable) { $result['url_normalization_rejections']++; continue; }
                    if ($this->directories->classify($candidate['normalized_url'])) { $result['directory_or_social_skipped']++; continue; }
                    $result['normalized_urls']++;
                    $domain = $candidate['normalized_domain'];
                    if (isset($seenDomains[$domain])) { $result['duplicate_claim_domains']++; continue; }
                    $seenDomains[$domain] = true;
                    if (DB::table('web_index_documents')->where('normalized_domain', $domain)->exists()) $result['current_corpus_duplicates']++;
                    else $result['unique_usable_domains']++;
                }
            }
        }
        return $result;
    }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $rows = DB::table('discovery_candidates as c')
            ->join('discovery_candidate_sources as s', function ($join): void { $join->on('s.candidate_id', '=', 'c.id')->on('s.tenant_id', '=', 'c.tenant_id'); })
            ->whereIn('s.source', ['openstreetmap', 'verified_open_discovery'])->orderBy('s.source_reference')
            ->limit(min(500, max(1, $limit * 10)))->get(['c.company_name', 'c.city', 'c.country', 'c.industry', 's.source_reference', 's.source_metadata']);
        $seen = []; $seenDomains = []; $completed = 0;
        $maxEntities = min(100, max(1, (int) ($options['max_entities'] ?? 25)));
        $maxFetches = min(100, max(1, (int) ($options['max_fetches'] ?? 50)));
        $deadline = microtime(true) + min(600, max(10, (int) ($options['max_runtime_seconds'] ?? 300)));
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; return; }
            $meta = is_array($row->source_metadata) ? $row->source_metadata : (json_decode((string) $row->source_metadata, true) ?: []);
            $tags = (array) data_get($meta, 'tags', []);
            foreach (['wikidata' => 'linked_entity', 'operator:wikidata' => 'operator_entity', 'brand:wikidata' => 'brand_entity'] as $tag => $evidenceType) {
                $entityId = (string) ($tags[$tag] ?? '');
                if (! preg_match('/^Q[1-9][0-9]{0,11}$/', $entityId) || isset($seen[$entityId])) continue;
                if ($this->counts['entities_seen'] >= $maxEntities || $this->counts['p856_urls'] >= $maxFetches) { $this->counts['entity_budget_exhausted']++; return; }
                if ($cursor && strcmp($entityId, (string) ($cursor['entity_id'] ?? '')) < 0) continue;
                $seen[$entityId] = true; $this->counts['entities_seen']++;
                if (trim((string) $row->company_name) !== '' && (trim((string) $row->industry) !== '' || trim((string) $row->city) !== '')) {
                    $this->counts['business_like_entities']++;
                }
                try { $claims = $this->wikidata->officialWebsiteEntities($entityId); }
                catch (Throwable) { $this->counts['source_failures']++; continue; }
                foreach (array_slice($claims, 0, 3) as $claim) {
                    if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; return; }
                    if ($cursor && $entityId === ($cursor['entity_id'] ?? null) && strcmp((string) $claim['url'], (string) ($cursor['website_url'] ?? '')) <= 0) continue;
                    if (++$completed > min(500, max(1, $limit))) return;
                    $this->counts['p856_urls']++;
                    try { $candidate = $this->domains->normalize((string) $claim['url']); }
                    catch (Throwable) { $this->counts['url_normalization_rejections']++; continue; }
                    if ($this->directories->classify($candidate['normalized_url'])) { $this->counts['directory_or_social_skipped']++; continue; }
                    $this->counts['normalized_urls']++;
                    if (isset($seenDomains[$candidate['normalized_domain']])) { $this->counts['duplicate_claim_domains']++; $this->counts['duplicates']++; continue; }
                    $seenDomains[$candidate['normalized_domain']] = true;
                    if (DB::table('web_index_documents')->where('normalized_domain', $candidate['normalized_domain'])->exists()) $this->counts['current_corpus_duplicates']++;
                    else $this->counts['unique_usable_domains']++;
                    try {
                        $document = $this->fetcher->fetch((string) $claim['url'], ['organization_name' => $claim['label'] ?? $row->company_name, 'city' => $row->city, 'country' => $row->country]);
                        $document += ['business_category' => $row->industry, 'source' => $this->name(), 'source_reference' => $entityId.':P856',
                            'source_timestamp' => now()->toIso8601String(), 'evidence_type' => $evidenceType,
                            'source_query' => ['entity_id' => $entityId, 'entity_label' => $claim['label'] ?? null, 'property' => 'P856', 'linked_osm_reference' => $row->source_reference],
                            '_cursor' => ['entity_id' => $entityId, 'website_url' => $claim['url']]];
                        $this->counts['documents_indexed']++;
                        yield $document;
                    } catch (Throwable $error) { $this->recordWebsiteFailure($error); }
                }
            }
        }
    }

    private function recordWebsiteFailure(Throwable $error): void
    {
        $message = strtoupper($error->getMessage());
        $key = match (true) {
            str_contains($message, 'ROBOTS_DENIED') => 'robots_denied',
            str_contains($message, 'UNSAFE_REDIRECT'), str_contains($message, 'CROSS-DOMAIN'), str_contains($message, 'REDIRECT LIMIT') => 'unsafe_redirects',
            str_contains($message, 'UNSAFE_URL'), str_contains($message, 'PUBLIC ADDRESS'), str_contains($message, 'NON-PUBLIC') => 'url_policy_rejections',
            str_contains($message, 'ROBOTS_UNAVAILABLE') => 'robots_unavailable',
            str_contains($message, 'NON_HTML') => 'non_html',
            str_contains($message, 'OVERSIZED') => 'oversized',
            str_contains($message, 'PARSE_FAILURE') => 'parse_failures',
            default => 'transport_or_http_failures',
        };
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $this->counts['source_failures']++;
    }
}
