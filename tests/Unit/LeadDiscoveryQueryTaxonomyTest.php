<?php

namespace Tests\Unit;

use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use Tests\TestCase;

class LeadDiscoveryQueryTaxonomyTest extends TestCase
{
    public function test_ecommerce_retail_campaign_generates_deterministic_location_buckets(): void
    {
        $queries = app(LeadDiscoveryQueryTaxonomy::class)->forCampaign(['industry' => 'E-commerce & Retail', 'country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai']);
        self::assertCount(11, $queries);
        self::assertSame('online-retailer', $queries[0]['key']);
        self::assertSame('online retailer Chennai Tamil Nadu IN', $queries[0]['query']);
        self::assertSame('specialty online retailer Chennai Tamil Nadu IN', $queries[10]['query']);
        self::assertSame([], app(LeadDiscoveryQueryTaxonomy::class)->forCampaign(['industry' => 'Healthcare', 'country_code' => 'IN']));
    }
}
