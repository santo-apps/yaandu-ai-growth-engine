<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\Http;

final class OpenStreetMapOverpassLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    public function key(): string { return 'osm_overpass'; }

    public function validateConfiguration(array $configuration): void
    {
        parent::validateConfiguration($configuration);
        $endpoint = (string) config('lead_discovery.endpoints.osm_overpass');
        if (! $this->isHttpsEndpoint($endpoint) || (string) parse_url($endpoint, PHP_URL_HOST) !== 'overpass-api.de' || trim((string) config('lead_discovery.user_agent')) === '') {
            throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Configure the official HTTPS Overpass endpoint and an operator-identifying User-Agent before use.');
        }
    }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('osm_overpass', 'OPEN_PERSISTABLE', true, false, true, false, true, true, true, true, true,
            false, [], ['company_name', 'website', 'email', 'phone', 'address', 'coordinates', 'industry'], true, true, true, true,
            'Open Database License (ODbL)', 'https://www.openstreetmap.org/copyright', '© OpenStreetMap contributors', 1, 'bounded_geographic_partitions', 'OPEN_PERSISTABLE');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $bbox = $query->partition['bbox'] ?? null;
        if (! is_array($bbox) || count($bbox) !== 4) throw new LiveLeadSourceException('SOURCE_PARTITION_REQUIRED', 'OpenStreetMap discovery requires a bounded geographic partition.');
        [$south, $west, $north, $east] = array_map('floatval', array_values($bbox));
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east || abs($north - $south) > 2 || abs($east - $west) > 2) {
            throw new LiveLeadSourceException('SOURCE_PARTITION_INVALID', 'The OpenStreetMap area is outside the configured small-partition limit.');
        }
        $tags = $this->tagsForIndustry($query->industryKey);
        $selectors = [];
        foreach ($tags as $key => $values) {
            foreach ($values as $value) {
                $selector = '["'.addcslashes($key, '"\\').'"'.($value === '*' ? '' : '="'.addcslashes($value, '"\\').'"').']';
                $selectors[] = 'nwr["name"]'.$selector.'('.$south.','.$west.','.$north.','.$east.');';
            }
        }
        $offset = $this->boundedOffset($query->cursor);
        $limit = min(50, max(1, $query->limit));
        $ql = '[out:json][timeout:20];('.implode('', $selectors).');out center tags '.($limit * ($offset + 1)).';';
        $endpoint = (string) config('lead_discovery.endpoints.osm_overpass');
        try {
            $response = Http::asForm()->acceptJson()->withHeaders(['User-Agent' => (string) config('lead_discovery.user_agent')])
                ->connectTimeout(4)->timeout(25)->post($endpoint, ['data' => $ql]);
        } catch (\Throwable) { throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'OpenStreetMap Overpass did not respond within its bounded timeout.'); }
        if ($response->status() === 429 || $response->status() === 504 || $response->status() >= 500) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'OpenStreetMap Overpass is busy or rate limited.', max(5, (int) $response->header('Retry-After', 10)));
        if (! $response->successful()) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'OpenStreetMap Overpass returned an unsuccessful response.');
        $elements = data_get($response->json(), 'elements');
        if (! is_array($elements)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'OpenStreetMap Overpass returned an invalid element list.');
        $candidates = [];
        foreach (array_slice($elements, $offset * $limit, $limit) as $element) {
            if (! is_array($element) || ! in_array($element['type'] ?? null, ['node', 'way', 'relation'], true) || ! is_numeric($element['id'] ?? null)) continue;
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            if (empty($tags['name'])) continue;
            $lat = $element['lat'] ?? data_get($element, 'center.lat'); $lon = $element['lon'] ?? data_get($element, 'center.lon');
            $sourceCategory = $this->firstString($tags, ['shop', 'office', 'craft', 'amenity', 'tourism', 'healthcare', 'industrial', 'company']);
            $attributes = [...$tags, 'latitude' => $lat, 'longitude' => $lon, 'country_code' => strtoupper((string) ($query->partition['country_code'] ?? '')),
                'city' => $query->partition['city'] ?? null, 'region' => $query->partition['region'] ?? null,
                'source_category' => $sourceCategory, 'source_industry' => $sourceCategory,
                'normalized_industry' => config('lead_discovery.industries.'.$query->industryKey),
                'osm_object_id' => $element['type'].'/'.$element['id']];
            $candidates[] = $this->mapCandidate($this->key(), ['source_record_id' => $element['type'].'/'.$element['id'], 'source_record_type' => $element['type'],
                'source_url' => 'https://www.openstreetmap.org/'.$element['type'].'/'.$element['id'], 'raw_metadata' => ['tags' => $tags]], $attributes,
                ['license_key' => 'odbl', 'license_name' => 'ODbL', 'license_url' => 'https://www.openstreetmap.org/copyright', 'attribution_text' => '© OpenStreetMap contributors']);
        }
        $exhausted = count($elements) <= ($offset + 1) * $limit || $offset >= 4;
        return new DiscoveryPage($candidates, $exhausted ? null : (string) ($offset + 1), $exhausted, $response->header('X-Request-Id'), ['bbox' => $bbox, 'offset' => $offset]);
    }

    private function tagsForIndustry(?string $industry): array
    {
        $groups = config('lead_discovery.osm_categories', []);
        if ($industry && isset($groups[$industry])) return $groups[$industry];
        if ($industry) return [];
        $combined = [];
        foreach ($groups as $category) foreach ($category as $key => $values) {
            $combined[$key] = array_values(array_unique([...($combined[$key] ?? []), ...$values]));
        }

        return $combined;
    }

    private function boundedOffset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') return 0;
        if (! ctype_digit($cursor) || (int) $cursor > 4) throw new LiveLeadSourceException('SOURCE_CURSOR_INVALID', 'The OpenStreetMap page offset is invalid.');
        return (int) $cursor;
    }

    private function isHttpsEndpoint(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
