<?php

namespace App\LeadDiscovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class WikidataLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    private const CC0 = 'https://creativecommons.org/publicdomain/zero/1.0/';

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
        return new LeadSourceCapabilities('wikidata', 'OPEN_PERSISTABLE', true, false, true, true, true, false, false, false, false,
            true, [], ['legal_name', 'country', 'industry', 'website', 'headquarters', 'coordinates', 'identifiers'], true, true, false, false,
            'CC0 1.0', self::CC0, null, 12, 'country_industry_sparql', 'OPEN_PERSISTABLE');
    }

    public function supportsIndustryKey(?string $industryKey): bool
    {
        return $industryKey !== null && isset(config('lead_discovery.wikidata_industries', [])[$industryKey]['industry']);
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $country = app(LeadDiscoveryCountryCatalog::class)->normalize((string) ($query->partition['country_code'] ?? $query->country));
        if ($country === null) throw new LiveLeadSourceException('SOURCE_PARTITION_REQUIRED', 'Wikidata requires a valid ISO country partition.');
        $industryKey = $query->industryKey;
        $configuredQids = $industryKey === null ? [] : (array) data_get(config('lead_discovery.wikidata_industries', []), $industryKey.'.industry', []);
        $industryQids = array_values(array_filter(array_keys($configuredQids), static fn ($qid) => is_string($qid) && preg_match('/^Q[1-9][0-9]*$/D', $qid) === 1));
        if ($industryKey === null || $industryQids === []) {
            throw new LiveLeadSourceException('SOURCE_QUERY_UNSUPPORTED', 'Wikidata has no curated industry mapping for this campaign.');
        }

        $limit = min(10, max(1, (int) config('lead_discovery.wikidata_result_count', 10)), max(1, $query->limit));
        $maxPages = min(5, max(1, (int) config('lead_discovery.wikidata_max_pages', 5)));
        [$afterQid, $pageNumber] = $this->cursor($query->cursor, $maxPages);
        $industryValues = implode(' ', array_map(static fn (string $qid) => 'wd:'.$qid, $industryQids));
        $keyset = $afterQid === null ? '' : ' FILTER(STR(?item) > "https://www.wikidata.org/entity/'.$afterQid.'")';

        // The inner select bounds candidate identities using only country and
        // curated industry properties. The outer query enriches that bounded
        // set with direct English labels and stored facts; no label SERVICE or
        // transitive organization-class traversal is involved.
        $sparql = 'SELECT ?item (SAMPLE(?labelValue) AS ?label) (SAMPLE(?country) AS ?country) '
            .'(SAMPLE(?industry) AS ?industry) (SAMPLE(?website) AS ?website) '
            .'(SAMPLE(?headquarters) AS ?headquarters) (SAMPLE(?headquartersLabelValue) AS ?headquartersLabel) WHERE { '
            .'{ SELECT DISTINCT ?item WHERE { ?country wdt:P297 "'.$country.'". '
            .'?item wdt:P17 ?country; wdt:P452 ?industry. VALUES ?industry { '.$industryValues.' }'.$keyset.' } LIMIT '.$limit.' } '
            .'?country wdt:P297 "'.$country.'". ?item wdt:P17 ?country; wdt:P452 ?industry. '
            .'VALUES ?industry { '.$industryValues.' } '
            .'?item rdfs:label ?labelValue. FILTER(LANG(?labelValue) = "en") '
            .'OPTIONAL { ?item wdt:P856 ?website. } '
            .'OPTIONAL { ?item wdt:P159 ?headquarters. ?headquarters rdfs:label ?headquartersLabelValue. FILTER(LANG(?headquartersLabelValue) = "en") } '
            .'} GROUP BY ?item LIMIT '.$limit;

        try {
            $response = Http::acceptJson()->withHeaders([
                'User-Agent' => (string) config('lead_discovery.wikidata_user_agent'),
                'Accept-Encoding' => 'gzip, deflate',
            ])->connectTimeout(5)->timeout(30)->get((string) config('lead_discovery.endpoints.wikidata'), [
                'query' => $sparql, 'format' => 'json',
            ]);
        } catch (ConnectionException) {
            throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'Wikidata Query Service did not respond within its bounded timeout.');
        }

        $status = $response->status();
        if ($status === 429) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'Wikidata Query Service is rate limited.', $this->retryAfter($response->header('Retry-After')));
        if ($status === 504) throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'Wikidata Query Service timed out.');
        if (in_array($status, [502, 503], true) || $status >= 500) throw new LiveLeadSourceException('SOURCE_TEMPORARILY_UNAVAILABLE', 'Wikidata Query Service is temporarily unavailable.');
        if ($status === 400) throw new LiveLeadSourceException('SOURCE_QUERY_INVALID', 'Wikidata rejected the bounded query.');
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'Wikidata Query Service returned an unsuccessful response.');
        $bindings = data_get($response->json(), 'results.bindings');
        if (! is_array($bindings)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'Wikidata returned an invalid result set.');

        $candidates = [];
        $lastQid = null;
        foreach ($bindings as $binding) {
            $uri = data_get($binding, 'item.value');
            if (! is_string($uri) || ! preg_match('#^https://www\.wikidata\.org/entity/(Q[1-9][0-9]*)$#D', $uri, $match)) continue;
            $qid = $match[1];
            if ($lastQid === null || $this->qidIsGreater($qid, $lastQid)) $lastQid = $qid;
            $label = trim((string) data_get($binding, 'label.value', ''));
            if ($label === '') continue;
            $website = data_get($binding, 'website.value');
            $headquartersQid = $this->qidFromEntityUri(data_get($binding, 'headquarters.value'));
            $headquartersLabel = trim((string) data_get($binding, 'headquartersLabel.value', ''));
            $industryLabel = (string) ($configuredQids[$this->qidFromEntityUri(data_get($binding, 'industry.value')) ?? ''] ?? '');
            $normalizedIndustry = (string) data_get(config('lead_discovery.industries', []), $industryKey, '');
            $rawEvidence = [
                'item' => data_get($binding, 'item'), 'country' => data_get($binding, 'country'),
                'industry' => data_get($binding, 'industry'), 'website' => data_get($binding, 'website'),
                'headquarters' => data_get($binding, 'headquarters'), 'headquartersLabel' => data_get($binding, 'headquartersLabel'),
            ];
            $candidates[] = $this->mapCandidate($this->key(), [
                'source_record_id' => $qid, 'source_record_type' => 'wikidata_entity',
                'source_url' => 'https://www.wikidata.org/wiki/'.$qid,
                'raw_metadata' => ['binding' => $rawEvidence, 'discovery_scope' => $headquartersLabel !== '' ? 'country_industry_headquarters_evidence' : 'country_industry',
                    'country_code' => $country, 'industry_key' => $industryKey],
            ], [
                'official_name' => $label, 'website' => is_string($website) && $website !== '' ? $website : null,
                'country_code' => $country, 'normalized_industry' => $normalizedIndustry !== '' ? $normalizedIndustry : null,
                'source_industry' => $industryLabel !== '' ? $industryLabel : null,
                'city' => $headquartersLabel !== '' ? $headquartersLabel : null, 'wikidata_qid' => $qid,
                'headquarters_qid' => $headquartersQid,
            ], ['license_key' => 'cc0', 'license_name' => 'CC0 1.0', 'license_url' => self::CC0]);
        }

        $next = null;
        if (count($bindings) >= $limit && $pageNumber < $maxPages && $lastQid !== null) $next = $lastQid.':'.($pageNumber + 1);
        $sha = hash('sha256', $sparql);
        return new DiscoveryPage($candidates, $next, $next === null, null, [
            'query_key' => $query->queryKey, 'industry_key' => $industryKey, 'country_code' => $country,
            'result_count' => count($bindings), 'logical_request_count' => 1, 'query_sha256' => $sha, 'stage_count' => 2,
        ]);
    }

    /** @return array{?string,int} */
    private function cursor(?string $cursor, int $maxPages): array
    {
        if ($cursor === null || $cursor === '') return [null, 1];
        if (! preg_match('/^(Q[1-9][0-9]*):([2-9][0-9]*)$/D', $cursor, $match)
            || (int) $match[2] > $maxPages) {
            throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The Wikidata result cursor is invalid or exceeds the page limit.');
        }
        return [$match[1], (int) $match[2]];
    }

    private function qidFromEntityUri(mixed $uri): ?string
    {
        return is_string($uri) && preg_match('#^https://www\.wikidata\.org/entity/(Q[1-9][0-9]*)$#D', $uri, $match) ? $match[1] : null;
    }

    private function qidIsGreater(string $left, string $right): bool
    {
        $leftNumber = substr($left, 1);
        $rightNumber = substr($right, 1);
        return strlen($leftNumber) > strlen($rightNumber)
            || (strlen($leftNumber) === strlen($rightNumber) && strcmp($leftNumber, $rightNumber) > 0);
    }

    private function retryAfter(?string $header): int
    {
        if ($header !== null && ctype_digit(trim($header))) return min(3600, max(1, (int) $header));
        if ($header !== null && ($date = strtotime($header)) !== false) return min(3600, max(1, $date - time()));
        return 10;
    }
}
