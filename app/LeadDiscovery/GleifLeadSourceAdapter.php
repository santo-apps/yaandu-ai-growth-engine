<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\Http;

final class GleifLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    public function key(): string { return 'gleif'; }

    public function validateConfiguration(array $configuration): void
    {
        parent::validateConfiguration($configuration);
        $url = (string) config('lead_discovery.endpoints.gleif');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || (string) parse_url($url, PHP_URL_HOST) !== 'api.gleif.org') {
            throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Configure the official GLEIF HTTPS API endpoint before use.');
        }
    }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('gleif', 'OPEN_PERSISTABLE', true, false, true, false, true, false, false, false, true,
            true, [], ['legal_name', 'registration_id', 'address', 'entity_status', 'legal_form', 'relationships'], true, true, false, false,
            'CC0 1.0', 'https://www.gleif.org/en/lei-data/access-and-use-lei-data', null, 1, 'api_delta_or_golden_copy', 'OPEN_PERSISTABLE');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $page = $this->page($query->cursor); $limit = min(50, max(1, $query->limit));
        $params = ['page[size]' => $limit, 'page[number]' => $page, 'filter[entity.status]' => 'ACTIVE'];
        $country = strtoupper((string) ($query->partition['country_code'] ?? $query->country));
        if ($country !== '') $params['filter[entity.legalAddress.country]'] = $country;
        if (trim($query->keywords) !== '') $params['filter[entity.legalName]'] = mb_substr($query->keywords, 0, 100);
        try { $response = Http::acceptJson()->connectTimeout(4)->timeout(20)->get((string) config('lead_discovery.endpoints.gleif'), $params); }
        catch (\Throwable) { throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'GLEIF did not respond within its bounded timeout.'); }
        if ($response->status() === 429 || $response->status() >= 500) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'GLEIF is busy or rate limited.', max(5, (int) $response->header('Retry-After', 10)));
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'GLEIF returned an unsuccessful response.');
        $rows = data_get($response->json(), 'data');
        if (! is_array($rows)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'GLEIF returned an invalid entity list.');
        $candidates = [];
        foreach ($rows as $item) {
            if (! is_array($item)) continue;
            $attr = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $lei = $this->firstString($attr, ['lei']); $entity = $attr['entity'] ?? [];
            $name = $this->firstString((array) ($entity['legalName'] ?? []), ['name']);
            if (! $lei || ! $name) continue;
            $address = (array) ($entity['legalAddress'] ?? []);
            $attributes = ['lei' => $lei, 'legal_name' => $name, 'country_code' => $address['country'] ?? $country,
                'city' => $address['city'] ?? null, 'region' => $address['region'] ?? null, 'postal_code' => $address['postalCode'] ?? null,
                'address' => implode(', ', array_filter([$address['addressLines'][0] ?? null, $address['city'] ?? null, $address['region'] ?? null, $address['country'] ?? null])),
                'entity_status' => $entity['status'] ?? null, 'raw_metadata' => $attr];
            $candidates[] = $this->mapCandidate($this->key(), ['source_record_id' => $lei, 'source_record_type' => 'gleif_lei',
                'source_url' => 'https://api.gleif.org/api/v1/lei-records/'.rawurlencode($lei), 'raw_metadata' => $attr], $attributes,
                ['license_key' => 'cc0', 'license_name' => 'CC0 1.0', 'license_url' => 'https://www.gleif.org/en/lei-data/access-and-use-lei-data']);
        }
        $next = count($rows) < $limit || $page >= 5 ? null : (string) ($page + 1);
        return new DiscoveryPage($candidates, $next, $next === null, null, ['country_code' => $country ?: null, 'page' => $page]);
    }

    private function page(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') return 1;
        if (! ctype_digit($cursor) || (int) $cursor < 1 || (int) $cursor > 5) throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The GLEIF result page is invalid.');
        return (int) $cursor;
    }
}
