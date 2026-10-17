<?php

namespace Tests\Unit;

use App\LeadDiscovery\LeadDiscoverySourcePlanner;
use Tests\TestCase;

class LeadDiscoverySourcePlannerTest extends TestCase
{
    public function test_planner_selects_relevant_open_sources_and_explains_sources_without_industry_filters(): void
    {
        $sources = [
            (object) ['id' => 'osm-id', 'source_key' => 'osm_overpass'],
            (object) ['id' => 'wikidata-id', 'source_key' => 'wikidata'],
            (object) ['id' => 'gleif-id', 'source_key' => 'gleif'],
            (object) ['id' => 'mca-id', 'source_key' => 'india_ogd_mca'],
        ];
        $campaign = (object) ['id' => 'campaign', 'industry' => 'E-commerce & Retail', 'normalized_industry_key' => 'ecommerce_retail',
            'country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai', 'configuration' => '{}'];

        $plan = app(LeadDiscoverySourcePlanner::class)->planWithDiagnostics($campaign, $sources);

        self::assertEqualsCanonicalizing(['osm-id', 'wikidata-id'], $plan['selected_source_ids']);
        self::assertContains(['source_id' => 'gleif-id', 'reason_code' => 'SOURCE_QUERY_UNSUPPORTED'], $plan['skipped_sources']);
        self::assertContains(['source_id' => 'mca-id', 'reason_code' => 'SOURCE_QUERY_UNSUPPORTED'], $plan['skipped_sources']);
        self::assertNotEmpty($plan['tasks']);
    }

    public function test_planner_reports_osm_as_not_applicable_without_a_safe_geographic_partition(): void
    {
        $plan = app(LeadDiscoverySourcePlanner::class)->planWithDiagnostics((object) ['id' => 'campaign', 'industry' => 'E-commerce & Retail',
            'normalized_industry_key' => 'ecommerce_retail', 'country_code' => 'SA', 'region' => null, 'city' => 'Jeddah', 'configuration' => '{}'],
            [(object) ['id' => 'osm-id', 'source_key' => 'osm_overpass']]);

        self::assertSame([], $plan['tasks']);
        self::assertSame([['source_id' => 'osm-id', 'reason_code' => 'SOURCE_NOT_APPLICABLE']], $plan['skipped_sources']);
    }
}
