<?php

namespace App\WebsiteResolution;

use App\Crawling\RobotsRules;
use App\Crawling\UrlPolicy;
use App\Discovery\DiscoveryQuery;
use App\Discovery\OpenStreetMapDiscoverySource;
use App\Discovery\DomainNormalizer;
use Throwable;

final class OpenStreetMapWebsiteIndexIngestionSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['osm_queries' => 0, 'osm_query_failures' => 0, 'osm_businesses' => 0, 'explicit_website_refs' => 0, 'candidate_checks' => 0,
        'url_normalization_rejections' => 0, 'dns_resolution_failures' => 0, 'ssrf_policy_rejections' => 0,
        'robots_dns_failures' => 0, 'robots_tls_failures' => 0, 'robots_timeouts' => 0, 'robots_redirect_rejections' => 0, 'robots_transport_failures' => 0,
        'robots_http_unavailable' => 0, 'robots_denied' => 0, 'candidate_dns_failures' => 0, 'candidate_tls_failures' => 0,
        'candidate_timeouts' => 0, 'candidate_redirect_rejections' => 0, 'candidate_transport_failures' => 0, 'candidate_http_failures' => 0, 'candidate_non_html' => 0, 'candidate_oversize' => 0,
        'indexed_pages' => 0, 'duplicate_osm_refs' => 0, 'directory_or_social_skipped' => 0, 'time_budget_exhausted' => 0];

    public function __construct(
        private readonly OpenStreetMapDiscoverySource $osm,
        private readonly DomainNormalizer $domains,
        private readonly UrlPolicy $policy,
        private readonly RobotsRules $robots,
        private readonly WebsiteIdentityPageExtractor $extractor,
        private readonly DirectoryDomainClassifier $directories,
    ) {}

    public function name(): string { return 'osm_public_websites'; }

    public function metrics(): array { return $this->counts; }

    public function documents(int $limit): iterable
    {
        $limit = min(50, max(1, $limit));
        $deadline = microtime(true) + min(600, max(15, (int) config('website_resolution.index_ingestion_max_duration_seconds', 300)));
        $maxChecks = min(100, max(1, (int) config('website_resolution.osm_index_max_fetch_attempts', 100)), max(1, $limit * 2));
        $categories = array_slice(['business', 'hospitality', 'healthcare', 'furniture'], 0, max(1, min(4, (int) config('website_resolution.osm_index_queries_per_ingestion', 4))));
        $seenRefs = [];
        foreach ($categories as $category) {
            if ($this->counts['indexed_pages'] >= $limit || $this->counts['candidate_checks'] >= $maxChecks) break;
            if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break; }
            $this->counts['osm_queries']++;
            try {
                $businesses = $this->osm->search(new DiscoveryQuery(['city' => config('website_resolution.osm_index_city', 'Dubai'),
                    'country' => config('website_resolution.osm_index_country', 'UAE'), 'business_category' => $category], 100));
            } catch (Throwable) {
                $this->counts['osm_query_failures']++;
                continue;
            }
            $this->counts['osm_businesses'] += count($businesses);
            foreach ($businesses as $business) {
                if ($this->counts['indexed_pages'] >= $limit || $this->counts['candidate_checks'] >= $maxChecks) break;
                if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break 2; }
                $reference = (string) ($business['source_reference'] ?? '');
                if ($reference !== '' && isset($seenRefs[$reference])) { $this->counts['duplicate_osm_refs']++; continue; }
                if ($reference !== '') $seenRefs[$reference] = true;
                $url = trim((string) ($business['website'] ?? ''));
                if ($url === '') continue;
                $this->counts['explicit_website_refs']++;
                $this->counts['candidate_checks']++;
                try {
                    if ($this->directories->classify($url)) { $this->counts['directory_or_social_skipped']++; continue; }
                    $normalized = $this->domains->normalize($url);
                } catch (Throwable) {
                    $this->counts['url_normalization_rejections']++;
                    continue;
                }
                try {
                    $this->policy->validatePublicHttpUrl($normalized['normalized_url']);
                } catch (Throwable $error) {
                    $this->counts[$this->failureMetric($error, 'url')]++;
                    continue;
                }
                try {
                    $parts = parse_url($normalized['normalized_url']);
                    $robotsUrl = ($parts['scheme'] ?? 'https').'://'.$normalized['normalized_domain'].'/robots.txt';
                    $robotsResponse = $this->policy->fetch($robotsUrl, timeoutSeconds: 5);
                } catch (Throwable $error) {
                    $this->counts[$this->failureMetric($error, 'robots')]++;
                    continue;
                }
                if (! in_array($robotsResponse->status(), [404, 410], true) && ! $robotsResponse->successful()) {
                    $this->counts['robots_http_unavailable']++;
                    $this->counts['robots_http_status_'.$robotsResponse->status()] = ($this->counts['robots_http_status_'.$robotsResponse->status()] ?? 0) + 1;
                    continue;
                }
                if (! $this->robots->allows($robotsResponse->body(), (string) (parse_url($normalized['normalized_url'], PHP_URL_PATH) ?: '/'))) {
                    $this->counts['robots_denied']++;
                    continue;
                }
                try {
                    $this->counts['page_fetches']++;
                    $response = $this->policy->fetch($normalized['normalized_url'], timeoutSeconds: 8);
                } catch (Throwable $error) {
                    $this->counts[$this->failureMetric($error, 'candidate')]++;
                    continue;
                }
                if (! $response->successful()) {
                    $this->counts['candidate_http_failures']++;
                    $this->counts['candidate_http_status_'.$response->status()] = ($this->counts['candidate_http_status_'.$response->status()] ?? 0) + 1;
                    continue;
                }
                if (! str_contains(mb_strtolower((string) $response->header('Content-Type')), 'text/html')) {
                    $this->counts['candidate_non_html']++;
                    continue;
                }
                if (strlen($response->body()) > (int) config('website_resolution.max_candidate_page_bytes', 1_000_000)) {
                    $this->counts['candidate_oversize']++;
                    continue;
                }
                try {
                    $page = $this->extractor->extract($response->body(), $normalized['normalized_url']);
                    $tags = (array) data_get($business, 'source_metadata.tags', []);
                    $phones = array_values(array_filter([$page['telephone'] ?? null, $tags['phone'] ?? null, $tags['contact:phone'] ?? null]));
                    $emails = array_values(array_filter([$page['email'] ?? null, $tags['email'] ?? null, $tags['contact:email'] ?? null]));
                    $this->counts['indexed_pages']++;
                    yield [
                        'canonical_url' => $normalized['normalized_url'], 'normalized_domain' => $normalized['normalized_domain'],
                        'page_title' => $page['title'] ?? null, 'organization_name' => $page['name'] ?? $business['name'],
                        'description' => $page['description'] ?? null, 'visible_text_excerpt' => $page['text_excerpt'] ?? null,
                        'country' => $page['country'] ?? $business['country'] ?? null, 'city' => $page['city'] ?? $business['city'] ?? null,
                        'address_text' => $page['address'] ?? null, 'phone_values' => $phones, 'email_values' => $emails,
                        'structured_data' => ['page_identity' => array_intersect_key($page, array_flip(['name', 'legal_name', 'url', 'telephone', 'email', 'address', 'city', 'country', 'same_as']))],
                        'document_type' => 'business', 'source' => $this->name(), 'source_reference' => $reference,
                        'source_timestamp' => $business['source_timestamp'] ?? null,
                    ];
                } catch (Throwable $error) {
                    $this->counts[$this->failureMetric($error, 'candidate')]++;
                }
            }
        }
    }

    private function failureMetric(Throwable $error, string $stage): string
    {
        $message = mb_strtolower($error->getMessage());
        if ($stage === 'url') {
            if (str_contains($message, 'could not be resolved')) return 'dns_resolution_failures';
            if (str_contains($message, 'non-public') || str_contains($message, 'private') || str_contains($message, 'reserved')) return 'ssrf_policy_rejections';
            return 'url_normalization_rejections';
        }
        if (str_contains($message, 'redirect') || str_contains($message, 'downgrade')) return $stage.'_redirect_rejections';
        if (str_contains($message, 'resolve host') || str_contains($message, 'could not be resolved') || str_contains($message, 'name or service not known')) return $stage.'_dns_failures';
        if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) return $stage.'_tls_failures';
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) return $stage.'_timeouts';
        if (preg_match('/curl error\s+(\d+)/i', $message, $matches)) return $stage.'_curl_error_'.$matches[1];
        return $stage.'_transport_failures';
    }
}
