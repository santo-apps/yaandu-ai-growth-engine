<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\DB;

final class GeographicRelevanceClassifier
{
    public const CLASSIFICATIONS = ['city_match', 'region_match', 'country_match', 'location_unknown', 'location_mismatch'];

    public function __construct(private readonly LeadDiscoveryCountryCatalog $countries) {}

    /** @return array{classification:string,requested_location:array,evidence_location:array,evidence_source:?string,explanation:string} */
    public function classify(array|object $campaign, iterable $evidence): array
    {
        $campaign = (array) $campaign;
        $requested = [
            'city' => $this->displayValue($campaign['city'] ?? null),
            'region' => $this->displayValue($campaign['region'] ?? null),
            'country' => $this->countryLabel($this->normalizeCountry($campaign['country_code'] ?? null)),
        ];
        $targetCountry = $this->normalizeCountry($campaign['country_code'] ?? null);
        $targetRegion = $this->normalizeRegion($campaign['region'] ?? null, $targetCountry);
        $targetCity = $this->normalizeCity($campaign['city'] ?? null);

        $values = ['country_code' => [], 'region' => [], 'city' => []];
        foreach ($evidence as $item) {
            $item = (array) $item;
            $field = (string) ($item['field_name'] ?? '');
            if (! isset($values[$field]) || (float) ($item['confidence_score'] ?? 0) < 0.7) continue;
            $attribution = $this->decodeAttribution($item['attribution'] ?? null);
            if (! $this->isSupportedLocationEvidence($field, (string) ($attribution['source_key'] ?? ''), $attribution)) continue;
            $raw = $item['normalized_value'] ?? $item['raw_value'] ?? null;
            $normalized = match ($field) {
                'country_code' => $this->normalizeCountry($raw),
                'region' => $this->normalizeRegion($raw, $targetCountry),
                'city' => $this->normalizeCity($raw),
            };
            if ($normalized === null) continue;
            $values[$field][] = [
                'normalized' => $normalized,
                'display' => $this->displayValue($item['raw_value'] ?? $item['normalized_value'] ?? null),
                'source_key' => (string) ($attribution['source_key'] ?? ''),
                'confidence' => (float) ($item['confidence_score'] ?? 0),
                'collected_at' => (string) ($item['collected_at'] ?? ''),
            ];
        }

        $country = $this->best($values['country_code']);
        $region = $this->best($values['region']);
        $city = $this->best($values['city']);
        $evidenceLocation = [
            'city' => $city['display'] ?? null,
            'region' => $region['display'] ?? null,
            'country' => isset($country['normalized']) ? $this->countryLabel($country['normalized']) : null,
        ];
        $source = $city['source_key'] ?? $region['source_key'] ?? $country['source_key'] ?? null;

        $classification = 'location_unknown';
        if ($country !== null && $targetCountry !== null && $country['normalized'] !== $targetCountry) {
            $classification = 'location_mismatch';
        } elseif ($targetCountry !== null && $country === null) {
            $classification = 'location_unknown';
        } elseif ($targetRegion !== null && $region !== null && $region['normalized'] !== $targetRegion) {
            $classification = 'location_mismatch';
        } elseif ($targetCity !== null && $city !== null && $city['normalized'] !== $targetCity) {
            $classification = 'location_mismatch';
        } elseif ($targetCity !== null && $city !== null && $city['normalized'] === $targetCity) {
            $classification = 'city_match';
        } elseif ($targetRegion !== null && $region !== null && $region['normalized'] === $targetRegion) {
            $classification = 'region_match';
        } elseif ($country !== null && $targetCountry !== null && $country['normalized'] === $targetCountry) {
            // When the campaign asks only for a country, this is a complete
            // match to its intent. With finer city/region intent it is partial.
            $classification = 'country_match';
        }

        return [
            'classification' => $classification,
            'requested_location' => $requested,
            'evidence_location' => $evidenceLocation,
            'evidence_source' => $source,
            'explanation' => $this->explanation($classification, $requested, $evidenceLocation),
        ];
    }

    /** @return array{classification:string,requested_location:array,evidence_location:array,evidence_source:?string,explanation:string} */
    public function forCandidate(string $tenantId, object $candidate, object|array $campaign): array
    {
        $evidence = DB::table('lead_discovery_candidate_evidence')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $candidate->campaign_id)
            ->where('candidate_id', $candidate->id)
            ->whereIn('field_name', ['country_code', 'region', 'city'])
            ->get();

        return $this->classify($campaign, $evidence);
    }

    public function normalizeCountry(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') return null;
        $input = $this->key((string) $value);
        $code = $this->countries->normalize((string) $value);
        if ($code !== null) return $code;
        foreach ($this->countries->all() as $countryCode => $label) {
            if ($this->key((string) $label) === $input) return (string) $countryCode;
        }

        return match ($input) {
            'united arab emirates', 'uae' => 'AE',
            'united states', 'united states of america' => 'US',
            'united kingdom', 'great britain' => 'GB',
            default => null,
        };
    }

    public function countryLabel(?string $code): ?string
    {
        return $code === null ? null : ($this->countries->all()[$code] ?? $code);
    }

    public function displayLocation(array|object $campaign, iterable $evidence): array
    {
        return $this->classify($campaign, $evidence);
    }

    private function isSupportedLocationEvidence(string $field, string $source, array $attribution): bool
    {
        $marker = (string) ($attribution['geographic_evidence_field'] ?? '');
        if ($source === 'osm_overpass') {
            return $marker === match ($field) {
                'country_code' => 'addr:country', 'region' => 'addr:state', 'city' => 'addr:city', default => '',
            };
        }

        $allowed = [
            'wikidata' => ['country_code' => ['P297'], 'city' => ['P159']],
            'gleif' => ['country_code' => ['legalAddress.country'], 'region' => ['legalAddress.region'], 'city' => ['legalAddress.city']],
            'india_ogd_mca' => ['country_code' => ['dataset_jurisdiction'], 'region' => ['Registered State']],
            'csv_upload' => ['country_code' => ['country'], 'region' => ['region'], 'city' => ['city']],
        ];

        return in_array($marker, $allowed[$source][$field] ?? [], true);
    }

    private function decodeAttribution(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value)) return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function best(array $values): ?array
    {
        if ($values === []) return null;
        usort($values, static fn (array $left, array $right): int =>
            ($right['confidence'] <=> $left['confidence']) ?: strcmp($left['source_key'], $right['source_key']) ?: strcmp($right['collected_at'], $left['collected_at']));
        return $values[0];
    }

    private function normalizeCity(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') return null;
        $key = $this->key((string) $value);
        return match ($key) {
            'madras' => 'chennai',
            default => $key,
        };
    }

    private function normalizeRegion(mixed $value, ?string $country): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') return null;
        $key = $this->key((string) $value);
        if ($country === 'IN' && $key === 'tn') return 'tamil nadu';
        return $key;
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function displayValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 120) : null;
    }

    private function explanation(string $classification, array $requested, array $evidence): string
    {
        $requestedPlace = implode(', ', array_filter([$requested['city'], $requested['region'], $requested['country']]));
        $evidencePlace = implode(', ', array_filter([$evidence['city'], $evidence['region'], $evidence['country']]));

        return match ($classification) {
            'city_match' => 'Source evidence confirms '.$evidencePlace.'.',
            'region_match' => 'Source evidence confirms '.$evidencePlace.'; '.($requested['city'] ?: 'the requested city').' is not verified.',
            'country_match' => $requested['city'] || $requested['region']
                ? 'Source evidence confirms '.$evidencePlace.' only; '.implode(' / ', array_filter([$requested['city'], $requested['region']])).' is not verified.'
                : 'Source evidence confirms the requested country; no city or region was confirmed.',
            'location_mismatch' => 'Reliable source location evidence conflicts with the requested geography.',
            default => $requestedPlace !== '' ? 'No reliable source location evidence is available for '.$requestedPlace.'.' : 'No reliable source location evidence is available.',
        };
    }
}
