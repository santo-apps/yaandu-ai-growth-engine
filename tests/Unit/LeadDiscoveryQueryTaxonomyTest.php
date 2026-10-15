<?php

namespace Tests\Unit;

use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use Tests\TestCase;

class LeadDiscoveryQueryTaxonomyTest extends TestCase
{
    public function test_industry_taxonomy_generates_deterministic_queries_and_all_industry_batches(): void
    {
        $queries = app(LeadDiscoveryQueryTaxonomy::class)->forCampaign(['industry' => 'E-commerce & Retail', 'country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai']);
        self::assertCount(2, $queries);
        self::assertSame('ecommerce_retail-retail', $queries[0]['key']);
        self::assertSame('retail company', $queries[0]['query']);
        self::assertSame('Unsupported sector', app(LeadDiscoveryQueryTaxonomy::class)->forCampaign(['industry' => 'Unsupported sector', 'country_code' => 'IN'])[0]['query']);
        self::assertGreaterThan(2, count(app(LeadDiscoveryQueryTaxonomy::class)->forCampaign(['industry' => null, 'country_code' => null])));
    }
}
