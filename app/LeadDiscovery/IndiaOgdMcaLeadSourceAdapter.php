<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\Http;

final class IndiaOgdMcaLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    public function key(): string { return 'india_ogd_mca'; }

    public function validateConfiguration(array $configuration): void
    {
        parent::validateConfiguration($configuration);
        if (! filled(config('services.data_gov_in.api_key')) || ! preg_match('/^[a-f0-9-]{20,80}$/i', (string) config('lead_discovery.mca_resource_id'))) {
            throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Configure an official data.gov.in API key and Company Master Data resource identifier before use.');
        }
    }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('india_ogd_mca', 'OPEN_PERSISTABLE', true, false, true, false, true, false, false, false, true,
            false, ['IN'], ['legal_name', 'registration_id', 'entity_status', 'legal_form', 'registered_state', 'capital', 'registration_date'], true, true, true, false,
            'India OGD / NDSAP dataset terms', 'https://www.data.gov.in/catalog/company-master-data', 'Ministry of Corporate Affairs, Government of India via data.gov.in', 1, 'official_api_or_streaming_download', 'OPEN_PERSISTABLE',
            'Use only the official catalog resource/API or official downloadable file; resource terms and attribution apply.');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        if (strtoupper((string) ($query->partition['country_code'] ?? $query->country)) !== 'IN') throw new LiveLeadSourceException('SOURCE_NOT_APPLICABLE', 'MCA Company Master Data applies only to India.');
        $apiKey = (string) config('services.data_gov_in.api_key');
        $resource = (string) config('lead_discovery.mca_resource_id');
        $page = $this->page($query->cursor);
        $limit = min(100, max(1, $query->limit));
        $params = ['api-key' => $apiKey, 'format' => 'json', 'offset' => ($page - 1) * $limit, 'limit' => $limit];
        if (trim($query->keywords) !== '') $params['filters[Company Name]'] = mb_substr($query->keywords, 0, 100);
        try {
            $response = Http::acceptJson()->connectTimeout(4)->timeout(20)->get('https://api.data.gov.in/resource/'.$resource, $params);
        } catch (\Throwable) { throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'The official OGD catalog did not respond within its bounded timeout.'); }
        if ($response->status() === 429 || $response->status() >= 500) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'The official OGD catalog is busy or rate limited.', max(5, (int) $response->header('Retry-After', 10)));
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'The official OGD catalog returned an unsuccessful response.');
        $payload = $response->json(); $rows = data_get($payload, 'records');
        if (! is_array($rows)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'The official OGD catalog returned an invalid record list.');
        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $cin = $this->firstString($row, ['CIN', 'cin', 'Corporate Identification Number']);
            $name = $this->firstString($row, ['Company Name', 'company_name', 'CompanyName']);
            if (! $cin || ! $name) continue;
            $row = [...$row, 'cin' => $cin, 'legal_name' => $name, 'country_code' => 'IN',
                'registration_identifier_jurisdiction' => 'IN', 'registration_identifier_registry' => 'MCA', 'registration_identifier_type' => 'CIN',
                'entity_status' => $this->firstString($row, ['Company Status', 'company_status']),
                'registered_state' => $this->firstString($row, ['Registered State', 'registered_state']), 'source_industry' => $this->firstString($row, ['Company Category', 'Company Class'])];
            $candidates[] = $this->mapCandidate($this->key(), ['source_record_id' => $cin, 'source_record_type' => 'mca_company',
                'source_url' => 'https://www.data.gov.in/catalog/company-master-data', 'raw_metadata' => $row], $row,
                ['license_key' => 'india_ogd_ndsap', 'license_name' => 'India OGD / NDSAP', 'license_url' => 'https://www.data.gov.in/catalog/company-master-data',
                    'attribution_text' => 'Ministry of Corporate Affairs, Government of India via data.gov.in']);
        }
        $next = count($rows) < $limit || $page >= 5 ? null : (string) ($page + 1);
        return new DiscoveryPage($candidates, $next, $next === null, null, ['resource_id' => $resource, 'page' => $page]);
    }

    private function page(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') return 1;
        if (! ctype_digit($cursor) || (int) $cursor < 1 || (int) $cursor > 5) throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The OGD result page is invalid.');
        return (int) $cursor;
    }
}
