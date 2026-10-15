<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class BraveSearchLeadSourceAdapter implements SearchLeadSourceAdapterInterface
{
    public function key(): string { return 'brave_search'; }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('brave_search', 'conditional_search_api', true, false, true, false, true, true, true, true, true,
            false, [], ['company_name', 'website'], true, true, true, false, 'Brave Search API agreement',
            'https://api-dashboard.search.brave.com/documentation/resources/terms-of-service', null, 30, 'bounded_query', 'CONDITIONAL',
            'Persistent result storage requires explicit agreement rights; disabled by default.');
    }

    public function validateConfiguration(array $configuration): void
    {
        if (! config('lead_discovery.live_enabled')) throw new LiveLeadSourceException('SOURCE_DISABLED', 'Live discovery is disabled by application configuration.');
        if (($configuration['source_enabled'] ?? false) !== true) throw new LiveLeadSourceException('SOURCE_DISABLED', 'The tenant source is disabled.');
        if (($configuration['authorization_status'] ?? null) !== 'authorized') throw new LiveLeadSourceException('SOURCE_NOT_AUTHORIZED', 'The tenant has not approved this source for use.');
        if (! filled(config('services.brave_search.key'))) throw new LiveLeadSourceException('SOURCE_CREDENTIAL_MISSING', 'Live search credentials are not configured.');
        if (($configuration['storage_rights_status'] ?? null) !== 'confirmed'
            || ($configuration['storage_rights_confirmed'] ?? false) !== true
            || trim((string) ($configuration['storage_rights_reference'] ?? '')) === '') {
            throw new LiveLeadSourceException('STORAGE_RIGHTS_NOT_CONFIRMED', 'Explicit persistent-storage rights are not confirmed for this source.');
        }
        if (trim((string) ($configuration['license_or_authorization'] ?? '')) === '') throw new LiveLeadSourceException('SOURCE_NOT_AUTHORIZED', 'Record the provider plan and authorization basis before enabling this source.');
    }

    public function readCsv(string $path, int $maximumRows): array
    {
        throw new InvalidArgumentException('The live search adapter does not accept CSV uploads.');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $offset = $this->offset($query->cursor);
        $limit = max(1, min(20, $query->limit));
        $country = strtoupper($query->country);
        if (! preg_match('/^[A-Z]{2}$/', $country)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'The campaign country is not supported by the search provider.');

        try {
            $response = Http::acceptJson()->withHeaders([
                'X-Subscription-Token' => (string) config('services.brave_search.key'),
            ])->connectTimeout(3)->timeout(10)->post('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query->keywords,
                'count' => $limit,
                'country' => $country,
                'search_lang' => 'en',
                'offset' => $offset,
                'safesearch' => 'moderate',
            ]);
        } catch (\Throwable) {
            throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'The live search provider could not be reached safely.');
        }

        if ($response->status() === 401 || $response->status() === 403) throw new LiveLeadSourceException('SOURCE_AUTH_FAILED', 'The live search provider rejected its configured credentials.');
        if ($response->status() === 429) {
            $providerCode = strtoupper((string) data_get($response->json(), 'error.code', ''));
            if ($providerCode === 'QUOTA_LIMITED') throw new LiveLeadSourceException('SOURCE_QUOTA_EXHAUSTED', 'The live search provider quota is unavailable.');
            throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'The live search provider rate limit was reached.', max(1, min(3600, (int) $response->header('Retry-After', 5))));
        }
        if ($response->status() === 402) throw new LiveLeadSourceException('SOURCE_QUOTA_EXHAUSTED', 'The live search provider quota is unavailable.');
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'The live search provider returned an unsuccessful response.');

        $payload = $response->json();
        $results = data_get($payload, 'web.results');
        if (! is_array($results)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'The live search provider response did not match the expected result format.');
        $mapped = [];
        foreach (array_slice($results, 0, $limit) as $index => $result) {
            if (! is_array($result) || ! is_string($result['url'] ?? null) || ! is_string($result['title'] ?? null)) continue;
            $url = trim($result['url']);
            if (! preg_match('#^https?://#i', $url) || $this->isExcludedResult($url)) continue;
            $mapped[] = [
                'company_name' => mb_substr(trim($result['title']), 0, 255),
                'website' => mb_substr($url, 0, 2048),
                'title' => mb_substr(trim($result['title']), 0, 500),
                'snippet' => mb_substr(trim((string) ($result['description'] ?? '')), 0, 1000),
                'source_url' => mb_substr($url, 0, 2048),
                'source_reference' => 'brave:'.($offset * $limit + $index + 1),
                'rank' => $offset * $limit + $index + 1,
                'country_code' => $country,
                'industry' => $query->industry,
                'city' => $query->city,
                'region' => $query->region,
            ];
        }
        $nextOffset = $offset + 1;
        $exhausted = count($results) < $limit || $nextOffset > 9;
        $requestId = $response->header('X-Request-Id');
        Log::info('Lead discovery source page completed.', [
            'operation' => 'lead_discovery_source_page', 'source_key' => $this->key(), 'query_key' => $query->queryKey,
            'result_count' => count($mapped), 'provider_status' => $response->status(),
        ]);
        return new DiscoveryPage($mapped, $exhausted ? null : (string) $nextOffset, $exhausted, is_string($requestId) ? mb_substr($requestId, 0, 100) : null,
            ['country' => $country, 'offset' => $offset]);
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') return 0;
        if (! ctype_digit($cursor) || (int) $cursor < 0 || (int) $cursor > 9) throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The saved search cursor is invalid.');
        return (int) $cursor;
    }

    private function isExcludedResult(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || preg_match('/(^|\.)(brave\.com|google\.[a-z.]+|bing\.com|facebook\.com|instagram\.com|linkedin\.com|youtube\.com|x\.com|twitter\.com)$/i', $host)) return true;
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return preg_match('/\.(pdf|jpg|jpeg|png|gif|webp|svg|zip)$/i', $path) === 1;
    }
}
