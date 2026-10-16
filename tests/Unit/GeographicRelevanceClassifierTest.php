<?php

namespace Tests\Unit;

use App\LeadDiscovery\DiscoveryEligibilityService;
use App\LeadDiscovery\GeographicRelevanceClassifier;
use Tests\TestCase;

class GeographicRelevanceClassifierTest extends TestCase
{
    private function classify(array $campaign, array $evidence): array
    {
        return app(GeographicRelevanceClassifier::class)->classify($campaign, $evidence);
    }

    private function evidence(string $field, string $value, string $source, string $marker, float $confidence = 0.9): array
    {
        return ['field_name' => $field, 'raw_value' => $value, 'normalized_value' => $value,
            'confidence_score' => $confidence, 'collected_at' => '2026-01-01T00:00:00Z',
            'attribution' => json_encode(['source_key' => $source, 'geographic_evidence_field' => $marker])];
    }

    public function test_classifies_exact_city_match_using_attributed_source_evidence(): void
    {
        $result = $this->classify(['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai'], [
            $this->evidence('country_code', 'India', 'osm_overpass', 'addr:country'),
            $this->evidence('region', 'TN', 'osm_overpass', 'addr:state'),
            $this->evidence('city', 'Madras', 'osm_overpass', 'addr:city'),
        ]);

        self::assertSame('city_match', $result['classification']);
        self::assertSame('Chennai, Tamil Nadu, India', implode(', ', array_filter($result['requested_location'])));
        self::assertSame('Madras', $result['evidence_location']['city']);
        self::assertSame('osm_overpass', $result['evidence_source']);
    }

    public function test_classifies_region_and_country_matches_without_inventing_finer_location(): void
    {
        $region = $this->classify(['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai'], [
            $this->evidence('country_code', 'IN', 'india_ogd_mca', 'dataset_jurisdiction'),
            $this->evidence('region', 'Tamil Nadu', 'india_ogd_mca', 'Registered State'),
        ]);
        self::assertSame('region_match', $region['classification']);
        self::assertNull($region['evidence_location']['city']);

        $country = $this->classify(['country_code' => 'AE', 'region' => null, 'city' => null], [
            $this->evidence('country_code', 'United Arab Emirates', 'gleif', 'legalAddress.country'),
        ]);
        self::assertSame('country_match', $country['classification']);
        self::assertSame('United Arab Emirates', $country['evidence_location']['country']);
    }

    public function test_country_only_evidence_is_partial_for_finer_campaign_intent(): void
    {
        $result = $this->classify(['country_code' => 'AE', 'region' => 'Dubai', 'city' => 'Dubai'], [
            $this->evidence('country_code', 'AE', 'wikidata', 'P297'),
        ]);

        self::assertSame('country_match', $result['classification']);
        self::assertNull($result['evidence_location']['city']);
        self::assertStringContainsString('only', $result['explanation']);
    }

    public function test_location_evidence_is_not_called_a_match_when_campaign_has_no_geography_intent(): void
    {
        self::assertSame('location_unknown', $this->classify(['country_code' => null, 'region' => null, 'city' => null], [
            $this->evidence('country_code', 'IN', 'wikidata', 'P297'),
        ])['classification']);
    }

    public function test_mismatch_is_detected_for_conflicting_country_region_and_city(): void
    {
        self::assertSame('location_mismatch', $this->classify(['country_code' => 'IN'], [
            $this->evidence('country_code', 'AE', 'gleif', 'legalAddress.country'),
        ])['classification']);
        self::assertSame('location_mismatch', $this->classify(['country_code' => 'IN', 'region' => 'Tamil Nadu'], [
            $this->evidence('country_code', 'IN', 'gleif', 'legalAddress.country'),
            $this->evidence('region', 'Karnataka', 'gleif', 'legalAddress.region'),
        ])['classification']);
        self::assertSame('location_mismatch', $this->classify(['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai'], [
            $this->evidence('country_code', 'IN', 'osm_overpass', 'addr:country'),
            $this->evidence('region', 'Tamil Nadu', 'osm_overpass', 'addr:state'),
            $this->evidence('city', 'Bengaluru', 'osm_overpass', 'addr:city'),
        ])['classification']);
    }

    public function test_missing_low_confidence_and_unattributed_values_remain_unknown(): void
    {
        $campaign = ['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai'];
        self::assertSame('location_unknown', $this->classify($campaign, [])['classification']);
        self::assertSame('location_unknown', $this->classify($campaign, [
            $this->evidence('city', 'Chennai', 'osm_overpass', 'addr:city', 0.69),
        ])['classification']);
        self::assertSame('location_unknown', $this->classify($campaign, [
            $this->evidence('city', 'Chennai', 'brave_search', 'query_city'),
            $this->evidence('country_code', 'IN', 'brave_search', 'campaign_country'),
        ])['classification']);
        self::assertSame('location_unknown', $this->classify($campaign, [
            ['field_name' => 'city', 'raw_value' => 'Chennai', 'normalized_value' => 'Chennai', 'confidence_score' => 0.99,
                'attribution' => json_encode(['source_key' => 'osm_overpass', 'geographic_evidence_field' => 'name'])],
        ])['classification']);
    }

    public function test_eligibility_rejects_geographic_mismatch_but_keeps_unknown_and_country_partial_for_review(): void
    {
        $service = new DiscoveryEligibilityService();
        $baseCandidate = ['company_name' => 'Example Co', 'normalized_domain' => 'example.test', 'official_domain_status' => 'verified', 'industry' => 'Retail'];
        $campaign = ['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai', 'industry' => 'Retail'];
        $source = ['enabled' => true, 'authorization_status' => 'authorized'];

        self::assertSame('rejected', $service->evaluate([...$baseCandidate, 'geographic_relevance' => 'location_mismatch'], $campaign, $source)['status']);
        self::assertSame('needs_review', $service->evaluate([...$baseCandidate, 'geographic_relevance' => 'location_unknown'], $campaign, $source)['status']);
        self::assertSame('needs_review', $service->evaluate([...$baseCandidate, 'geographic_relevance' => 'country_match'], $campaign, $source)['status']);
        self::assertSame('eligible', $service->evaluate([...$baseCandidate, 'geographic_relevance' => 'city_match'], $campaign, $source)['status']);
    }
}
