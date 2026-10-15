<?php

namespace App\LeadDiscovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OpenStreetMapOverpassLeadSourceAdapter extends AbstractOpenLeadSourceAdapter
{
    private const MAX_BBOX_DEGREES = 2.0;

    public function key(): string { return 'osm_overpass'; }

    public function validateConfiguration(array $configuration): void
    {
        parent::validateConfiguration($configuration);
        $endpoints = $this->approvedEndpoints();
        if ($endpoints === [] || trim((string) config('lead_discovery.user_agent')) === '') {
            throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Configure an approved HTTPS Overpass endpoint and an operator-identifying User-Agent before use.');
        }
        // Endpoints are application configuration, never tenant-controlled source data.
        foreach (['endpoint', 'endpoint_url', 'overpass_endpoint', 'overpass_endpoints'] as $key) {
            if (array_key_exists($key, $configuration)) {
                throw new LiveLeadSourceException('SOURCE_CONFIGURATION_MISSING', 'Overpass endpoint selection is controlled by application configuration.');
            }
        }
    }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('osm_overpass', 'OPEN_PERSISTABLE', true, false, true, false, true, true, true, true, true,
            false, [], ['company_name', 'website', 'email', 'phone', 'address', 'coordinates', 'industry'], true, true, true, true,
            'Open Database License (ODbL)', 'https://www.openstreetmap.org/copyright', '© OpenStreetMap contributors', 12, 'bounded_geographic_partitions', 'OPEN_PERSISTABLE');
    }

    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage
    {
        $this->validateConfiguration($sourceConfiguration);
        $bbox = $query->partition['bbox'] ?? null;
        if (! is_array($bbox) || count($bbox) !== 4) throw new LiveLeadSourceException('SOURCE_PARTITION_REQUIRED', 'OpenStreetMap discovery requires a bounded geographic partition.');
        [$south, $west, $north, $east] = array_map('floatval', array_values($bbox));
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east
            || abs($north - $south) > self::MAX_BBOX_DEGREES || abs($east - $west) > self::MAX_BBOX_DEGREES) {
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
        $selectors = array_values(array_unique($selectors));
        if ($selectors === []) throw new LiveLeadSourceException('SOURCE_QUERY_INVALID', 'No bounded OpenStreetMap selectors are configured for this industry.');

        $offset = $this->boundedOffset($query->cursor);
        $limit = min(50, max(1, $query->limit));
        $ql = '[out:json][timeout:'.(int) config('lead_discovery.overpass_query_timeout_seconds', 25).'];('.implode('', $selectors).');out center tags '.($limit * ($offset + 1)).';';
        [$response, $endpoint] = $this->requestWithAvailabilityFallback($ql);
        if (! $response->successful()) {
            $status = $response->status();
            if ($status === 429) throw new LiveLeadSourceException('SOURCE_RATE_LIMITED', 'OpenStreetMap Overpass is rate limited.', $this->retryAfter($response->header('Retry-After')));
            if ($status === 504) throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'OpenStreetMap Overpass timed out.');
            if ($status >= 500) throw new LiveLeadSourceException('SOURCE_TEMPORARILY_UNAVAILABLE', 'OpenStreetMap Overpass is temporarily unavailable.');
            throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'OpenStreetMap Overpass returned an unsuccessful response.');
        }
        $decoded = $response->json();
        $elements = data_get($decoded, 'elements');
        if (! is_array($decoded) || ! is_array($elements)) throw new LiveLeadSourceException('SOURCE_RESPONSE_INVALID', 'OpenStreetMap Overpass returned an invalid element list.');

        $candidates = [];
        foreach (array_slice($elements, $offset * $limit, $limit) as $element) {
            if (! is_array($element) || ! in_array($element['type'] ?? null, ['node', 'way', 'relation'], true) || ! is_numeric($element['id'] ?? null)) continue;
            $elementTags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            if (empty($elementTags['name'])) continue;
            $lat = $element['lat'] ?? data_get($element, 'center.lat'); $lon = $element['lon'] ?? data_get($element, 'center.lon');
            $sourceCategory = $this->firstString($elementTags, ['shop', 'office', 'craft', 'amenity', 'tourism', 'healthcare', 'industrial', 'company']);
            $attributes = [...$elementTags, 'latitude' => $lat, 'longitude' => $lon, 'country_code' => strtoupper((string) ($query->partition['country_code'] ?? '')),
                'city' => $query->partition['city'] ?? null, 'region' => $query->partition['region'] ?? null,
                'source_category' => $sourceCategory, 'source_industry' => $sourceCategory,
                'normalized_industry' => config('lead_discovery.industries.'.$query->industryKey),
                'osm_object_id' => $element['type'].'/'.$element['id']];
            $candidates[] = $this->mapCandidate($this->key(), ['source_record_id' => $element['type'].'/'.$element['id'], 'source_record_type' => $element['type'],
                'source_url' => 'https://www.openstreetmap.org/'.$element['type'].'/'.$element['id'], 'raw_metadata' => ['tags' => $elementTags]], $attributes,
                ['license_key' => 'odbl', 'license_name' => 'ODbL', 'license_url' => 'https://www.openstreetmap.org/copyright', 'attribution_text' => '© OpenStreetMap contributors']);
        }
        $exhausted = count($elements) <= ($offset + 1) * $limit || $offset >= 4;
        return new DiscoveryPage($candidates, $exhausted ? null : (string) ($offset + 1), $exhausted, $response->header('X-Request-Id'),
            ['bbox' => $bbox, 'offset' => $offset, 'endpoint_name' => $endpoint['name'], 'endpoint_url' => $endpoint['url']]);
    }

    /** @return array{0:\Illuminate\Http\Client\Response,1:array{url:string,name:string}} */
    private function requestWithAvailabilityFallback(string $ql): array
    {
        $endpoints = $this->approvedEndpoints();
        $lastAvailabilityCode = null;
        // Leave five seconds for the job to persist its outcome before Horizon's
        // 45-second worker timeout. Individual endpoint requests remain capped at 35s.
        $deadline = microtime(true) + max(1, (int) config('lead_discovery.overpass_logical_request_timeout_seconds', 40));
        foreach ($endpoints as $index => $endpoint) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) break;
            try {
                $response = Http::asForm()->acceptJson()->withHeaders(['User-Agent' => (string) config('lead_discovery.user_agent')])
                    ->connectTimeout(min((int) config('lead_discovery.overpass_connect_timeout_seconds', 5), $remaining))
                    ->timeout(min((int) config('lead_discovery.overpass_http_timeout_seconds', 35), $remaining))
                    ->post($endpoint['url'], ['data' => $ql]);
            } catch (ConnectionException) {
                $lastAvailabilityCode = 'SOURCE_TIMEOUT';
                if ($index < count($endpoints) - 1) continue;
                throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'OpenStreetMap Overpass did not respond within its bounded timeout.');
            }
            $status = $response->status();
            if ($status === 502 || $status === 503) {
                $lastAvailabilityCode = 'SOURCE_TEMPORARILY_UNAVAILABLE';
                if ($index < count($endpoints) - 1) continue;
                break;
            }
            if ($status === 504) {
                $lastAvailabilityCode = 'SOURCE_TIMEOUT';
                if ($index < count($endpoints) - 1) continue;
                break;
            }

            // Rate limits, client errors, and invalid success semantics are never bypassed.
            return [$response, $endpoint];
        }
        if ($lastAvailabilityCode === 'SOURCE_TIMEOUT') throw new LiveLeadSourceException('SOURCE_TIMEOUT', 'OpenStreetMap Overpass timed out or could not be reached.');
        if ($lastAvailabilityCode !== null) throw new LiveLeadSourceException('SOURCE_TEMPORARILY_UNAVAILABLE', 'OpenStreetMap Overpass is temporarily unavailable.');
        throw new LiveLeadSourceException('SOURCE_TEMPORARILY_UNAVAILABLE', 'OpenStreetMap Overpass is temporarily unavailable.');
    }

    /** @return list<array{url:string,name:string}> */
    private function approvedEndpoints(): array
    {
        $catalog = config('lead_discovery.overpass_endpoints', []);
        $allowedHosts = array_map('strtolower', (array) config('lead_discovery.overpass_approved_hosts', []));
        if (! is_array($catalog) || $catalog === [] || $allowedHosts === []) return [];
        $approved = [];
        foreach ($catalog as $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null) || ! is_string($entry['name'] ?? null)) return [];
            $url = trim($entry['url']); $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! in_array($host, $allowedHosts, true)
                || ($parts['path'] ?? null) !== '/api/interpreter' || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) return [];
            $approved[] = ['url' => $url, 'name' => trim($entry['name'])];
        }
        return $approved;
    }

    private function retryAfter(?string $header): int
    {
        if ($header === null || trim($header) === '') return 10;
        if (ctype_digit(trim($header))) return max(1, (int) trim($header));
        $timestamp = strtotime($header);
        return $timestamp === false ? 10 : max(1, $timestamp - time());
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
}
