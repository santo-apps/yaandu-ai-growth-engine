<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\Http;

final class WikidataLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    public function key(): string { return 'wikidata'; }

    public function validateConfiguration(array $configuration): void
    {
        parent::validateConfiguration($configuration);
        $url = (string) config('lead_discovery.endpoints.wikidata');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || (string) parse_url($url, PHP_URL_HOST) !== 'query.wikidata.org'
            || trim((string) config('lead_discovery.wikidata_user_agent')) === '') {
            throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Configure the official Wikidata HTTPS endpoint and an operator-identifying User-Agent before use.');
        }
    }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('wikidata', 'OPEN_PERSISTABLE', true, false, true, false, true, false, false, false, false,
            true, [], ['legal_name', 'country', 'industry', 'website', 'headquarters', 'coordinates', 'identifiers'], true, true, false, false,
            'CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/', null, 1, 'country_partitioned_sparql', 'OPEN_PERSISTABLE');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $country = strtoupper((string) ($query->partition['country_code'] ?? $query->country));
        if (app(LeadDiscoveryCountryCatalog::class)->normalize($country) === null) throw new LiveLeadSourceException('SOURCE_PARTITION_REQUIRED', 'Wikidata requires a valid ISO country partition.');
        $offset = $this->offset($query->cursor);
        $limit = min(50, max(1, $query->limit));
        $sparql = 'SELECT ?item ?itemLabel ?website ?industryLabel ?cityLabel WHERE { '
            .'?country wdt:P297 "'.$country.'". ?item wdt:P31/wdt:P279* wd:Q43229; wdt:P17 ?country. '
            .'OPTIONAL { ?item wdt:P856 ?website. } OPTIONAL { ?item wdt:P452 ?industry. } '
            .'OPTIONAL { ?item wdt:P159 ?city. } SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } '
            .' } ORDER BY ?item LIMIT '.$limit.' OFFSET '.($offset * $limit);
        try {
            $response = Http::acceptJson()->withHeaders(['User-Agent' => (string) config('lead_discovery.wikidata_user_agent'), 'Accept-Encoding' => 'gzip, deflate'])
                ->connectTimeout(4)->timeout(25)->get((string) config('lead_discovery.endpoints.wikidata'), ['query' => $sparql, 'format' => 'json']);
        } catch (\Throwable) { throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'Wikidata Query Service did not respond within its bounded timeout.'); }
        if ($response->status() === 429 || $response->status() === 503) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'Wikidata Query Service is rate limited or temporarily unavailable.', max(5, (int) $response->header('Retry-After', 10)));
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'Wikidata Query Service returned an unsuccessful response.');
        $bindings = data_get($response->json(), 'results.bindings');
        if (! is_array($bindings)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'Wikidata returned an invalid result set.');
        $candidates = [];
        foreach ($bindings as $binding) {
            $uri = data_get($binding, 'item.value');
            if (! is_string($uri) || ! preg_match('#^https://www\.wikidata\.org/entity/(Q[1-9][0-9]*)$#', $uri, $match)) continue;
            $qidValue = $match[1]; $label = (string) data_get($binding, 'itemLabel.value', '');
            if ($label === '') continue;
            $website = data_get($binding, 'website.value');
            $sourceIndustry = data_get($binding, 'industryLabel.value');
            $candidates[] = $this->mapCandidate($this->key(), ['source_record_id' => $qidValue, 'source_record_type' => 'wikidata_entity',
                'source_url' => 'https://www.wikidata.org/wiki/'.$qidValue, 'raw_metadata' => $binding], [
                    'official_name' => $label, 'website' => $website, 'country_code' => $country,
                    'normalized_industry' => app(LeadDiscoveryIndustryTaxonomy::class)->normalize(is_string($sourceIndustry) ? $sourceIndustry : null), 'source_industry' => $sourceIndustry,
                    'city' => data_get($binding, 'cityLabel.value'), 'wikidata_qid' => $qidValue,
                ], ['license_key' => 'cc0', 'license_name' => 'CC0 1.0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/']);
        }
        $next = count($bindings) < $limit || $offset >= 4 ? null : (string) ($offset + 1);
        return new DiscoveryPage($candidates, $next, $next === null, null, ['country_code' => $country, 'offset' => $offset, 'sparql_sha256' => hash('sha256', $sparql)]);
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') return 0;
        if (! ctype_digit($cursor) || (int) $cursor > 4) throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The Wikidata result offset is invalid.');
        return (int) $cursor;
    }
}
