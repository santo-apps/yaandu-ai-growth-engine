<?php

namespace App\WebsiteResolution;

use App\Discovery\DiscoveryQuery;
use App\Discovery\DomainNormalizer;
use App\Discovery\OpenStreetMapDiscoverySource;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OpenStreetMapWebsiteIndexIngestionSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['osm_queries' => 0, 'osm_query_failures' => 0, 'osm_businesses' => 0, 'business_records' => 0,
        'website_refs' => 0, 'contact_website_refs' => 0, 'brand_website_refs' => 0, 'operator_website_refs' => 0,
        'normalized_urls' => 0, 'unique_usable_domains' => 0, 'duplicate_urls' => 0, 'duplicate_domains' => 0,
        'url_normalization_rejections' => 0, 'directory_seed_refs' => 0, 'explicit_website_refs' => 0,
        'candidate_checks' => 0, 'page_fetches' => 0, 'identity_pages_fetched' => 0, 'identity_page_failures' => 0,
        'parsed_pages' => 0, 'business_identity_extracted' => 0, 'candidate_new_domains' => 0, 'existing_corpus_duplicates' => 0,
        'indexed_pages' => 0, 'duplicate_osm_refs' => 0, 'directory_or_social_skipped' => 0,
        'dns_resolution_failures' => 0, 'ssrf_policy_rejections' => 0, 'robots_denied' => 0, 'robots_unavailable' => 0,
        'candidate_dns_failures' => 0, 'candidate_timeouts' => 0, 'candidate_redirect_rejections' => 0,
        'candidate_transport_failures' => 0, 'candidate_http_failures' => 0, 'candidate_non_html' => 0,
        'candidate_oversize' => 0, 'parse_failures' => 0, 'time_budget_exhausted' => 0, 'fetch_budget_exhausted' => 0];

    private array $normalizedSeeds = [];
    private array $domainSeeds = [];

    public function __construct(
        private readonly OpenStreetMapDiscoverySource $osm,
        private readonly PublicWebsiteDocumentFetcher $fetcher,
        private readonly DomainNormalizer $domains,
        private readonly DirectoryDomainClassifier $directories,
    ) {}

    public function name(): string { return 'osm_public_websites'; }
    public function metrics(): array { return $this->counts; }

    /** Inspect one bounded OSM query without fetching candidate websites or writing to the index. */
    public function inspectSeeds(string $location, string $country, string $category, int $limit = 100): array
    {
        $category = mb_strtolower(trim($category));
        if (! array_key_exists($category, (array) config('discovery.osm_categories', []))) {
            throw new \InvalidArgumentException('The OSM seed category is not configured.');
        }
        $businesses = $this->osm->search(new DiscoveryQuery(['city' => $location, 'country' => $country, 'business_category' => $category], min(100, max(1, $limit))));
        $counts = ['source_records' => count($businesses), 'business_records' => count($businesses), 'website_refs' => 0, 'contact_website_refs' => 0,
            'brand_website_refs' => 0, 'operator_website_refs' => 0, 'normalized_urls' => 0,
            'usable_normalized_domains' => 0, 'existing_corpus_duplicates' => 0, 'duplicate_urls' => 0, 'duplicate_domains' => 0,
            'url_normalization_rejections' => 0, 'directory_or_social' => 0];
        $urls = []; $domains = [];
        $tagsToCount = ['website' => 'website_refs', 'contact:website' => 'contact_website_refs',
            'brand:website' => 'brand_website_refs', 'operator:website' => 'operator_website_refs'];
        foreach ($businesses as $business) {
            $tags = (array) data_get($business, 'source_metadata.tags', []);
            if ($tags === [] && ! empty($business['website'])) $tags['website'] = $business['website'];
            foreach ($tagsToCount as $tag => $counter) {
                $candidate = trim((string) ($tags[$tag] ?? ''));
                if ($candidate === '') continue;
                $counts[$counter]++;
                try { $normalized = $this->domains->normalize($candidate); }
                catch (Throwable) { $counts['url_normalization_rejections']++; continue; }
                $url = $normalized['normalized_url']; $domain = $normalized['normalized_domain'];
                if (isset($urls[$url])) { $counts['duplicate_urls']++; continue; }
                $urls[$url] = true; $counts['normalized_urls']++;
                if ($this->directories->classify($url)) { $counts['directory_or_social']++; continue; }
                if (isset($domains[$domain])) { $counts['duplicate_domains']++; continue; }
                $domains[$domain] = true;
                if (DB::table('web_index_documents')->where('normalized_domain', $domain)->exists()) $counts['existing_corpus_duplicates']++;
            }
        }
        $counts['usable_normalized_domains'] = count($domains);
        $counts['website_domain_yield_percent'] = $counts['business_records'] > 0
            ? round(100 * count($domains) / $counts['business_records'], 1) : 0.0;
        $counts['location'] = $location; $counts['category'] = $category;
        $counts['note'] = 'Normalized seed yield only; URL/DNS/robots/fetch safety is checked before indexing.';
        return $counts;
    }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $limit = min(500, max(1, $limit));
        $runtime = min(600, max(10, (int) ($options['max_runtime_seconds'] ?? config('website_resolution.index_ingestion_max_duration_seconds', 300))));
        $deadline = microtime(true) + $runtime;
        $priorMetrics = (array) ($options['_resume_metrics'] ?? []);
        $priorFetches = max(0, (int) ($priorMetrics['page_fetches'] ?? 0));
        $priorProcessed = max(0, (int) ($options['_prior_processed'] ?? 0));
        $maxChecks = min((int) ($options['max_fetches'] ?? 250), max(1, (int) config('website_resolution.osm_index_max_fetch_attempts', 250)), max(1, ($limit + $priorProcessed) * 2));
        $location = trim((string) ($options['location'] ?? config('website_resolution.osm_index_city', 'Dubai')));
        $country = trim((string) ($options['country'] ?? config('website_resolution.osm_index_country', 'UAE')));
        $requested = array_values(array_unique(array_filter((array) ($options['categories'] ?? [$options['category'] ?? 'business']), 'is_string')));
        $categories = array_slice($requested ?: ['business'], 0, 8);
        foreach ($categories as $category) if (! array_key_exists(mb_strtolower($category), (array) config('discovery.osm_categories', []))) {
            throw new \InvalidArgumentException('The OSM ingestion category is not configured.');
        }
        $seenRefs = [];
        $properties = ['website' => 'feature_website', 'contact:website' => 'feature_contact_website',
            'brand:website' => 'brand_website', 'operator:website' => 'operator_website'];

        foreach ($categories as $categoryIndex => $category) {
            if ($cursor && $categoryIndex < (int) ($cursor['category_index'] ?? 0)) continue;
            if ($this->counts['indexed_pages'] >= $limit) break;
            if ($priorFetches + $this->counts['page_fetches'] >= $maxChecks) { $this->counts['fetch_budget_exhausted']++; break; }
            if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break; }
            $this->counts['osm_queries']++;
            try {
                $businesses = $this->osm->search(new DiscoveryQuery(['city' => $location, 'country' => $country,
                    'business_category' => $category], min(100, max(1, (int) ($options['max_source_records'] ?? 100)))));
            } catch (Throwable) { $this->counts['osm_query_failures']++; continue; }
            $this->counts['osm_businesses'] += count($businesses);
            $this->counts['business_records'] += count($businesses);

            foreach ($businesses as $business) {
                if ($this->counts['indexed_pages'] >= $limit) break 2;
                if ($priorFetches + $this->counts['page_fetches'] >= $maxChecks) { $this->counts['fetch_budget_exhausted']++; break 2; }
                if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break 2; }
                $reference = (string) ($business['source_reference'] ?? '');
                if ($reference !== '' && isset($seenRefs[$reference])) { $this->counts['duplicate_osm_refs']++; continue; }
                if ($reference !== '') $seenRefs[$reference] = true;
                $tags = (array) data_get($business, 'source_metadata.tags', []);

                foreach ($properties as $tag => $evidenceType) {
                    $url = trim((string) ($tags[$tag] ?? ($tag === 'website' && $tags === [] ? ($business['website'] ?? '') : '')));
                    if ($url === '') continue;
                    $this->counts[match ($tag) {
                        'contact:website' => 'contact_website_refs', 'brand:website' => 'brand_website_refs',
                        'operator:website' => 'operator_website_refs', default => 'website_refs',
                    }]++;
                    try {
                        $normalizedSeed = $this->domains->normalize($url);
                        if (isset($this->normalizedSeeds[$normalizedSeed['normalized_url']])) $this->counts['duplicate_urls']++;
                        else { $this->normalizedSeeds[$normalizedSeed['normalized_url']] = true; $this->counts['normalized_urls']++; }
                        if ($this->directories->classify($normalizedSeed['normalized_url'])) $this->counts['directory_seed_refs']++;
                        elseif (isset($this->domainSeeds[$normalizedSeed['normalized_domain']])) $this->counts['duplicate_domains']++;
                        else {
                            $this->domainSeeds[$normalizedSeed['normalized_domain']] = true; $this->counts['unique_usable_domains']++;
                            if (DB::table('web_index_documents')->where('normalized_domain', $normalizedSeed['normalized_domain'])->exists()) $this->counts['existing_corpus_duplicates']++;
                            else $this->counts['candidate_new_domains']++;
                        }
                    } catch (Throwable) { $this->counts['url_normalization_rejections']++; }
                    $sourceReference = ($reference ?: 'osm:unknown').':'.$tag;
                    if ($cursor && $categoryIndex === (int) ($cursor['category_index'] ?? 0) && ! empty($cursor['source_reference'])) {
                        if (empty($cursor['cursor_reached'])) {
                            if ($sourceReference === $cursor['source_reference']) $cursor['cursor_reached'] = true;
                            continue;
                        }
                    }
                    if ($priorFetches + $this->counts['page_fetches'] >= $maxChecks) { $this->counts['fetch_budget_exhausted']++; break 2; }
                    if ($this->counts['indexed_pages'] >= $limit) break 2;
                    $this->counts['explicit_website_refs']++; $this->counts['candidate_checks']++;
                    try {
                        $document = $this->fetcher->fetch($url, [
                            'organization_name' => $business['name'] ?? null, 'city' => $business['city'] ?? null, 'country' => $business['country'] ?? null,
                            'phone_values' => array_values(array_filter([$tags['phone'] ?? null, $tags['contact:phone'] ?? null])),
                            'email_values' => array_values(array_filter([$tags['email'] ?? null, $tags['contact:email'] ?? null])),
                        ], max(0, $maxChecks - $priorFetches - $this->counts['page_fetches'] - 1));
                        $pageFetches = max(1, (int) ($document['_fetch_count'] ?? 1));
                        $this->counts['page_fetches'] += $pageFetches;
                        $this->counts['identity_pages_fetched'] += max(0, count((array) ($document['identity_pages'] ?? [])) - 1);
                        $this->counts['parsed_pages'] += count((array) ($document['identity_pages'] ?? []));
                        if (! empty($document['organization_name'])) $this->counts['business_identity_extracted']++;
                        foreach ((array) ($document['_identity_page_failures'] ?? []) as $failure) {
                            $this->counts['identity_page_failures']++;
                            $this->counts['failure_identity_page_'.preg_replace('/[^A-Z_]/', '', (string) $failure)] =
                                ($this->counts['failure_identity_page_'.preg_replace('/[^A-Z_]/', '', (string) $failure)] ?? 0) + 1;
                        }
                        unset($document['_fetch_count'], $document['_identity_page_failures']);
                        $this->counts['indexed_pages']++;
                        yield [...$document, 'source' => $this->name(), 'source_reference' => $sourceReference,
                            'business_category' => $business['industry'] ?? $category,
                            'source_timestamp' => $business['source_timestamp'] ?? null,
                            'source_query' => ['location' => $location, 'country' => $country, 'category' => $category, 'website_tag' => $tag],
                            'evidence_type' => $evidenceType,
                            '_cursor' => ['category_index' => $categoryIndex, 'source_reference' => $sourceReference]];
                    } catch (Throwable $error) { $this->counts['page_fetches']++; $this->recordFailure($error); }
                }
            }
        }
    }

    private function recordFailure(Throwable $error): void
    {
        $code = $error->getMessage();
        if ($code === 'DIRECTORY_EVIDENCE') { $this->counts['directory_or_social_skipped']++; return; }
        if ($code === 'ROBOTS_DENIED') { $this->counts['robots_denied']++; return; }
        if (str_contains($code, 'ROBOTS_UNAVAILABLE')) { $this->counts['robots_unavailable']++; return; }
        if (str_contains($code, 'ROBOTS_DNS_FAILURE')) { $this->counts['robots_dns_failures']++; return; }
        if (str_contains($code, 'ROBOTS_TIMEOUT')) { $this->counts['robots_timeouts']++; return; }
        if (str_contains($code, 'ROBOTS_UNSAFE_REDIRECT')) { $this->counts['robots_redirect_rejections']++; return; }
        if (str_contains($code, 'ROBOTS_TRANSPORT_FAILURE')) { $this->counts['robots_transport_failures']++; return; }
        if (str_contains($code, 'URL_NORMALIZATION_REJECTED')) { $this->counts['url_normalization_rejections']++; return; }
        if (str_contains($code, 'UNSAFE_URL')) { $this->counts['ssrf_policy_rejections']++; return; }
        if ($code === 'NON_HTML') { $this->counts['candidate_non_html']++; return; }
        if ($code === 'OVERSIZED') { $this->counts['candidate_oversize']++; return; }
        if ($code === 'PARSE_FAILURE') { $this->counts['parse_failures']++; return; }
        if (str_contains($code, 'DNS_FAILURE')) { $this->counts['candidate_dns_failures']++; return; }
        if (str_contains($code, 'TIMEOUT')) { $this->counts['candidate_timeouts']++; return; }
        if (str_contains($code, 'UNSAFE_REDIRECT')) { $this->counts['candidate_redirect_rejections']++; return; }
        if (in_array($code, ['HTTP_FAILURE', 'ROBOTS_UNAVAILABLE'], true)) { $this->counts['candidate_http_failures']++; return; }
        $this->counts['candidate_transport_failures']++;
    }
}
