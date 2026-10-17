<?php

namespace Tests\Unit;

use App\LeadDiscovery\DiscoveryQuery;
use App\LeadDiscovery\BraveSearchLeadSourceAdapter;
use App\LeadDiscovery\GleifLeadSourceAdapter;
use App\LeadDiscovery\IndiaOgdMcaLeadSourceAdapter;
use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use App\LeadDiscovery\LeadDiscoverySourcePlanner;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LeadSourceCapabilityCatalog;
use App\LeadDiscovery\OpenStreetMapOverpassLeadSourceAdapter;
use App\LeadDiscovery\WikidataLeadSourceAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadDiscoveryPhaseTwoBTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lead_discovery.live_enabled' => true,
            'lead_discovery.user_agent' => 'PulamTest/1.0 (ops@example.test)',
            'lead_discovery.wikidata_user_agent' => 'PulamTest/1.0 (ops@example.test)',
            'lead_discovery.mca_resource_id' => '12345678-1234-1234-1234-123456789abc',
            'services.data_gov_in.api_key' => 'synthetic-key',
            'lead_discovery.live_max_partitions' => 5,
            'lead_discovery.live_max_provider_requests' => 40,
        ]);
    }

    public function test_osm_query_mapping_attribution_and_bounded_partition_are_preserved(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [[
            'type' => 'node', 'id' => 123, 'lat' => 25.1, 'lon' => 55.2,
            'tags' => ['name' => 'Synthetic Clinic', 'healthcare' => 'clinic', 'contact:website' => 'https://clinic.example'],
        ]]], 200)]);
        $page = app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query('healthcare'), $this->approvedSource());
        self::assertCount(1, $page->candidates);
        self::assertSame('node/123', $page->candidates[0]['osm_object_id']);
        self::assertSame('Healthcare', $page->candidates[0]['industry']);
        self::assertSame('clinic', $page->candidates[0]['source_category']);
        self::assertNull($page->candidates[0]['country_code'], 'Campaign country must not be copied into candidate evidence.');
        self::assertNull($page->candidates[0]['city'], 'Campaign city must not be copied into candidate evidence.');
        self::assertNull($page->candidates[0]['region'], 'Campaign region must not be copied into candidate evidence.');
        self::assertSame('© OpenStreetMap contributors', $page->candidates[0]['attribution']['attribution_text']);
        self::assertTrue($page->exhausted);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'overpass-api.de')
            && str_contains(urldecode($request->body()), '25,55,25.2,55.4') && str_contains(urldecode($request->body()), 'out center tags 10;'));
    }

    public function test_brave_campaign_location_is_not_copied_to_search_result_candidate(): void
    {
        config(['services.brave_search.key' => 'synthetic-key']);
        Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => [[
            'url' => 'https://business.example/', 'title' => 'Synthetic Retailer', 'description' => 'Public search snippet',
        ]]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', 'Tamil Nadu', 'Chennai', 'retail', 'retail Chennai', 10, null,
            ['country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai'], 'ecommerce_retail');
        $page = app(BraveSearchLeadSourceAdapter::class)->search($query, $this->approvedSource());

        self::assertCount(1, $page->candidates);
        self::assertNull($page->candidates[0]['country_code'] ?? null);
        self::assertNull($page->candidates[0]['city'] ?? null);
        self::assertNull($page->candidates[0]['region'] ?? null);
        self::assertSame([], $page->candidates[0]['geographic_evidence_fields'] ?? []);
    }

    public function test_wikidata_uses_bounded_country_sparql_and_maps_qid_and_cc0(): void
    {
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => [[
            'item' => ['value' => 'https://www.wikidata.org/entity/Q123'], 'label' => ['value' => 'Synthetic Company'],
            'country' => ['value' => 'https://www.wikidata.org/entity/Q902'],
            'countryCode' => ['value' => 'AE'],
            'industry' => ['value' => 'https://www.wikidata.org/entity/Q484847'],
            'website' => ['value' => 'https://company.example'],
            'headquarters' => ['value' => 'https://www.wikidata.org/entity/Q1353'],
            'headquartersLabel' => ['value' => 'Dubai'],
        ]]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'AE', 'Dubai', 'Dubai', 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'AE'], 'ecommerce_retail');
        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertSame('Q123', $page->candidates[0]['wikidata_qid']);
        self::assertSame('E-commerce & Retail', $page->candidates[0]['industry']);
        self::assertSame('e-commerce', $page->candidates[0]['source_industry']);
        self::assertSame('Dubai', $page->candidates[0]['city'], 'City comes only from direct P159 headquarters evidence.');
        self::assertSame('https://www.wikidata.org/entity/Q1353', $page->candidates[0]['raw_metadata']['binding']['headquarters']['value']);
        self::assertSame('https://www.wikidata.org/wiki/Q123', $page->candidates[0]['source_url']);
        self::assertSame('CC0 1.0', $page->candidates[0]['attribution']['license_name']);
        self::assertSame(2, $page->providerMetadata['stage_count']);
        self::assertSame(1, $page->providerMetadata['logical_request_count']);
        self::assertTrue($page->exhausted);
        Http::assertSent(function ($request): bool {
            $sparql = (string) $request['query'];
            return $request->method() === 'GET' && str_contains($sparql, '?country wdt:P297 ?countryCode') && str_contains($sparql, 'FILTER(?countryCode = "AE")')
                && str_contains($sparql, 'wdt:P452 ?industry') && str_contains($sparql, 'VALUES ?industry { wd:Q484847 wd:Q126793 }')
                && str_contains($sparql, 'LIMIT 10') && ! str_contains($sparql, 'P31/P279')
                && ! str_contains($sparql, 'SERVICE wikibase:label') && ! str_contains(strtoupper($sparql), 'ORDER BY');
        });
    }

    public function test_wikidata_accepts_only_http_or_https_trusted_entity_uris_and_canonicalizes_provenance(): void
    {
        $binding = static fn (string $item, string $headquarters = 'http://www.wikidata.org/entity/Q1353'): array => [
            'item' => ['value' => $item], 'label' => ['value' => 'Synthetic Retailer'],
            'country' => ['value' => 'https://www.wikidata.org/entity/Q668'],
            'countryCode' => ['value' => 'IN'],
            'industry' => ['value' => 'https://www.wikidata.org/entity/Q484847'],
            'website' => ['value' => 'https://retailer.example'],
            'headquarters' => ['value' => $headquarters], 'headquartersLabel' => ['value' => 'Dubai'],
        ];
        $bindings = [
            $binding('http://www.wikidata.org/entity/Q122575'),
            $binding('https://www.wikidata.org/entity/Q122575', 'https://www.wikidata.org/entity/Q1353'),
            $binding('http://www.wikidata.org.evil.com/entity/Q122575'),
            $binding('https://wikidata.org/entity/Q122575'),
            $binding('https://evil.example/entity/Q122575'),
            $binding('ftp://www.wikidata.org/entity/Q122575'),
            $binding('https://www.wikidata.org/wiki/Q122575'),
            $binding('https://www.wikidata.org/entity/Q0'),
            $binding('https://www.wikidata.org/entity/Q0122575'),
            $binding('https://www.wikidata.org/entity/Q122575?query=1'),
            $binding('https://www.wikidata.org/entity/Q122575#fragment'),
            $binding('https://www.wikidata.org:443/entity/Q122575'),
            $binding('122575'),
        ];
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => $bindings]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');

        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());

        self::assertCount(2, $page->candidates, 'Only the exact trusted HTTP and HTTPS entity URI forms should produce candidates.');
        foreach ($page->candidates as $candidate) {
            self::assertSame('Q122575', $candidate['wikidata_qid']);
            self::assertSame('https://www.wikidata.org/wiki/Q122575', $candidate['source_url']);
            self::assertSame('IN', $candidate['country_code']);
            self::assertSame('E-commerce & Retail', $candidate['industry']);
            self::assertSame('e-commerce', $candidate['source_industry']);
            self::assertSame('https://www.wikidata.org/entity/Q668', $candidate['raw_metadata']['binding']['country']['value']);
            self::assertSame('https://www.wikidata.org/entity/Q484847', $candidate['raw_metadata']['binding']['industry']['value']);
            self::assertSame('Q1353', $candidate['raw_metadata']['binding']['headquarters_qid']);
            self::assertSame('Dubai', $candidate['city']);
        }
        self::assertSame('Q1353', $page->candidates[0]['raw_metadata']['binding']['headquarters_qid'],
            'Headquarters QID extraction accepts the WDQS HTTP entity URI.');
        self::assertSame('Q1353', $page->candidates[1]['raw_metadata']['binding']['headquarters_qid'],
            'Headquarters QID extraction also accepts the HTTPS entity URI.');
        self::assertSame('http://www.wikidata.org/entity/Q122575', $page->candidates[0]['raw_metadata']['binding']['item']['value'],
            'The original provider binding remains available as provenance.');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'query.wikidata.org'));
    }

    public function test_wikidata_rejects_foreign_entity_uri_without_inventing_candidate_data(): void
    {
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => [[
            'item' => ['value' => 'https://wikidata.org.evil.example/entity/Q122575'],
            'label' => ['value' => 'Should Not Become A Candidate'],
            'country' => ['value' => 'https://www.wikidata.org/entity/Q668'],
            'industry' => ['value' => 'https://www.wikidata.org/entity/Q484847'],
        ]]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');

        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());

        self::assertSame([], $page->candidates);
        Http::assertSentCount(1);
    }

    public function test_wikidata_unmapped_industry_fails_closed_without_provider_request(): void
    {
        Http::fake();
        $query = new DiscoveryQuery('campaign', 'tenant', 'Marine Engineering', 'IN', null, null, 'custom', '', 10, null,
            ['country_code' => 'IN'], null);
        try {
            app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
            self::fail('An unmapped industry must not issue an all-organizations query.');
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame('SOURCE_QUERY_UNSUPPORTED', $error->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_wikidata_does_not_copy_campaign_city_when_headquarters_is_missing(): void
    {
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => [[
            'item' => ['value' => 'https://www.wikidata.org/entity/Q321'], 'label' => ['value' => 'Synthetic Retailer'],
            'country' => ['value' => 'https://www.wikidata.org/entity/Q668'], 'countryCode' => ['value' => 'IN'], 'industry' => ['value' => 'https://www.wikidata.org/entity/Q126793'],
        ]]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', 'Tamil Nadu', 'Chennai', 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');
        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertNull($page->candidates[0]['city']);
        self::assertSame('IN', $page->candidates[0]['country_code']);
        self::assertSame('country_industry', $page->candidates[0]['raw_metadata']['discovery_scope']);
    }

    public function test_wikidata_rejects_malformed_cursor_and_invalid_result_shape_safely(): void
    {
        $adapter = app(WikidataLeadSourceAdapter::class);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, 'Q123:6',
            ['country_code' => 'IN'], 'ecommerce_retail');
        Http::fake();
        try {
            $adapter->search($query, $this->approvedSource());
            self::fail('The sixth cursor page must be rejected.');
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame('SOURCE_CURSOR_INVALID', $error->errorCode);
        }
        Http::assertNothingSent();

        Http::fake(['query.wikidata.org/*' => Http::response('<html>error</html>', 200)]);
        $validQuery = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');
        try {
            $adapter->search($validQuery, $this->approvedSource());
            self::fail('An invalid JSON result shape must fail safely.');
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame('SOURCE_RESPONSE_INVALID', $error->errorCode);
            self::assertStringNotContainsString('<html>', $error->getMessage());
        }
    }

    public function test_wikidata_connection_failure_is_classified_as_timeout(): void
    {
        Http::fake(static function () { throw new \Illuminate\Http\Client\ConnectionException('synthetic network timeout'); });
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');
        try {
            app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
            self::fail('Connection failures must be classified as timeout.');
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame('SOURCE_TIMEOUT', $error->errorCode);
            self::assertStringNotContainsString('synthetic network timeout', $error->getMessage());
        }
    }

    public function test_wikidata_page_size_and_keyset_cursor_are_bounded(): void
    {
        config(['lead_discovery.wikidata_result_count' => 10, 'lead_discovery.wikidata_max_pages' => 5]);
        $bindings = [];
        for ($i = 1; $i <= 10; $i++) $bindings[] = ['item' => ['value' => 'https://www.wikidata.org/entity/Q'.(100 + $i)],
            'label' => ['value' => 'Retailer '.$i], 'industry' => ['value' => 'https://www.wikidata.org/entity/Q126793']];
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => $bindings]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 50, null,
            ['country_code' => 'IN'], 'ecommerce_retail');
        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertSame('Q110:2', $page->nextCursor);
        Http::assertSent(fn ($request) => str_contains((string) $request['query'], 'LIMIT 10') && ! str_contains((string) $request['query'], 'OFFSET'));

    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wikidataHttpFailures')]
    public function test_wikidata_classifies_http_failures_without_exposing_provider_body(int $status, string $expected): void
    {
        Http::fake(['query.wikidata.org/*' => Http::response('secret provider response body', $status, $status === 429 ? ['Retry-After' => '7'] : [])]);
        $query = new DiscoveryQuery('campaign', 'tenant', 'E-commerce & Retail', 'IN', null, null, 'industry-ecommerce_retail', '', 10, null,
            ['country_code' => 'IN'], 'ecommerce_retail');
        try {
            app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
            self::fail("Expected safe failure for HTTP {$status}.");
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame($expected, $error->errorCode);
            self::assertStringNotContainsString('secret provider response body', $error->getMessage());
            if ($status === 429) self::assertSame(7, $error->retryAfter);
        }
    }

    public static function wikidataHttpFailures(): array
    {
        return [
            'rate limited' => [429, 'SOURCE_RATE_LIMITED'], 'bad gateway' => [502, 'SOURCE_TEMPORARILY_UNAVAILABLE'],
            'unavailable' => [503, 'SOURCE_TEMPORARILY_UNAVAILABLE'], 'gateway timeout' => [504, 'SOURCE_TIMEOUT'],
            'invalid query' => [400, 'SOURCE_QUERY_INVALID'], 'other failure' => [404, 'SOURCE_RESPONSE_INVALID'],
        ];
    }

    public function test_mca_maps_official_identifiers_without_inventing_a_website(): void
    {
        Http::fake(['api.data.gov.in/*' => Http::response(['records' => [[
            'CIN' => 'U12345MH2020PTC123456', 'Company Name' => 'Synthetic MCA Ltd', 'Company Status' => 'Active',
            'Company Category' => 'Private', 'Registered State' => 'Maharashtra',
        ]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', '', 'IN', null, null, 'mca', '', 10, null, ['country_code' => 'IN']);
        $page = app(IndiaOgdMcaLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertSame('U12345MH2020PTC123456', $page->candidates[0]['registration_identifier']);
        self::assertSame('IN', $page->candidates[0]['registration_identifier_jurisdiction']);
        self::assertSame('MCA', $page->candidates[0]['registration_identifier_registry']);
        self::assertSame('CIN', $page->candidates[0]['registration_identifier_type']);
        self::assertNull($page->candidates[0]['website']);
        self::assertSame('India OGD / NDSAP', $page->candidates[0]['attribution']['license_name']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'api.data.gov.in/resource/'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officialSourceAvailabilityFailures')]
    public function test_official_sources_classify_transient_statuses_and_honor_http_date_retry_after(string $source, int $status, string $expected): void
    {
        $url = $source === 'india_ogd_mca' ? 'api.data.gov.in/*' : 'api.gleif.org/*';
        $headers = $status === 429 ? ['Retry-After' => gmdate(DATE_RFC7231, time() + 90)] : [];
        Http::fake([$url => Http::response(['temporary' => true], $status, $headers)]);
        $query = $source === 'india_ogd_mca'
            ? new DiscoveryQuery('campaign', 'tenant', '', 'IN', null, null, 'mca', '', 10, null, ['country_code' => 'IN'])
            : new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'gleif', '', 10, null, ['country_code' => 'AE']);
        $adapter = $source === 'india_ogd_mca' ? app(IndiaOgdMcaLeadSourceAdapter::class) : app(GleifLeadSourceAdapter::class);

        try {
            $adapter->search($query, $this->approvedSource());
            self::fail('Expected provider availability failure.');
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
            self::assertSame($expected, $error->errorCode);
            if ($status === 429) self::assertGreaterThanOrEqual(60, $error->retryAfter);
        }
    }

    public static function officialSourceAvailabilityFailures(): array
    {
        return [
            'MCA rate limit' => ['india_ogd_mca', 429, 'SOURCE_RATE_LIMITED'],
            'MCA temporary outage' => ['india_ogd_mca', 503, 'SOURCE_TEMPORARILY_UNAVAILABLE'],
            'MCA gateway timeout' => ['india_ogd_mca', 504, 'SOURCE_TIMEOUT'],
            'GLEIF rate limit' => ['gleif', 429, 'SOURCE_RATE_LIMITED'],
            'GLEIF temporary outage' => ['gleif', 503, 'SOURCE_TEMPORARILY_UNAVAILABLE'],
            'GLEIF gateway timeout' => ['gleif', 504, 'SOURCE_TIMEOUT'],
        ];
    }

    public function test_gleif_maps_lei_address_and_cc0_provenance(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(['data' => [[
            'attributes' => ['lei' => '529900T8BM49AURSDO55', 'entity' => [
                'legalName' => ['name' => 'Synthetic Entity LLC'], 'legalAddress' => ['country' => 'AE', 'city' => 'Dubai', 'addressLines' => ['Business District']],
                'status' => 'ACTIVE',
            ]],
        ]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'gleif', '', 10, null, ['country_code' => 'AE']);
        $page = app(GleifLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertSame('529900T8BM49AURSDO55', $page->candidates[0]['lei']);
        self::assertSame('Business District, Dubai, AE', $page->candidates[0]['address']);
        self::assertSame('CC0 1.0', $page->candidates[0]['attribution']['license_name']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['filter[entity.legalAddress.country]'] === 'AE');
    }

    public function test_source_capability_catalog_excludes_google_maps_from_selection(): void
    {
        $catalog = app(LeadSourceCapabilityCatalog::class);
        self::assertTrue($catalog->get('osm_overpass')->persistentStorageAllowed);
        self::assertTrue($catalog->get('osm_overpass')->shareAlikeRequired);
        self::assertSame('PROHIBITED_FOR_BULK_STORAGE', $catalog->get('google_maps')->governanceClass);
        self::assertFalse($catalog->get('google_maps')->supportsLiveQuery);
        self::assertTrue($catalog->get('brave_search')->persistentStorageAllowed, 'Storage is conditional on explicit, tenant-approved agreement rights at execution time.');
    }

    public function test_source_planner_is_bounded_and_excludes_india_only_mca_for_uae(): void
    {
        $registry = app(LeadSourceAdapterRegistry::class);
        $sources = collect(['osm_overpass', 'wikidata', 'india_ogd_mca', 'gleif'])->map(fn ($key, $i) => (object) [
            'id' => 'source-'.$i, 'source_key' => $key,
        ]);
        $campaign = (object) ['industry' => null, 'country_code' => 'AE', 'region' => null, 'city' => null,
            'configuration' => json_encode(['limits' => ['partitions' => 5, 'provider_requests' => 8]])];
        $tasks = app(LeadDiscoverySourcePlanner::class)->plan($campaign, $sources);
        self::assertLessThanOrEqual(8, count($tasks));
        self::assertNotContains('india_ogd_mca', array_map(fn ($task) => $task['source']->source_key, $tasks));
        self::assertContains('osm_overpass', array_map(fn ($task) => $task['source']->source_key, $tasks));
        foreach ($tasks as $task) self::assertNotEmpty($task['partition']['key']);
    }

    public function test_source_planner_creates_bounded_partitions_when_country_filter_is_empty(): void
    {
        $sources = collect(['osm_overpass', 'wikidata', 'gleif'])->map(fn ($key, $i) => (object) ['id' => 's'.$i, 'source_key' => $key]);
        $campaign = (object) ['industry' => 'IT & Software', 'country_code' => null, 'region' => null, 'city' => null,
            'configuration' => json_encode(['limits' => ['partitions' => 4, 'provider_requests' => 10]])];
        $tasks = app(LeadDiscoverySourcePlanner::class)->plan($campaign, $sources);
        self::assertLessThanOrEqual(10, count($tasks));
        self::assertGreaterThan(0, count($tasks));
        foreach (['wikidata', 'gleif'] as $sourceKey) {
            $sourcePartitions = array_filter($tasks, fn ($task) => $task['source']->source_key === $sourceKey);
            self::assertLessThanOrEqual(4, count(array_unique(array_map(fn ($task) => $task['partition']['key'], $sourcePartitions))));
        }
        self::assertNotContains('earth', array_map(fn ($task) => $task['partition']['key'], $tasks));
    }

    public function test_custom_industry_without_reliable_classification_skips_all_sources_that_cannot_filter_it(): void
    {
        $sources = collect(['osm_overpass', 'wikidata', 'india_ogd_mca', 'gleif'])->map(fn ($key, $i) => (object) ['id' => 's'.$i, 'source_key' => $key]);
        $campaign = (object) ['id' => 'custom-industry-sa', 'industry' => 'Marine Engineering', 'normalized_industry_key' => null,
            'country_code' => 'SA', 'region' => null, 'city' => 'Jeddah',
            'configuration' => json_encode(['limits' => ['partitions' => 4, 'provider_requests' => 10]])];
        $tasks = app(LeadDiscoverySourcePlanner::class)->plan($campaign, $sources);
        self::assertLessThanOrEqual(10, count($tasks));
        self::assertSame([], $tasks, 'No source can establish relevance for this custom industry and geography without a supported classifier or bounded OSM area.');
        self::assertNotContains('india_ogd_mca', array_map(fn ($task) => $task['source']->source_key, $tasks));
        self::assertNotContains('osm_overpass', array_map(fn ($task) => $task['source']->source_key, $tasks), 'OSM has no safe configured Saudi city bbox, but other providers remain usable.');
        self::assertNotContains('wikidata', array_map(fn ($task) => $task['source']->source_key, $tasks), 'Unmapped industries must not fall back to broad Wikidata organization discovery.');
    }

    public function test_any_country_any_industry_uses_bounded_country_and_query_windows(): void
    {
        $sources = collect(['wikidata', 'gleif', 'india_ogd_mca'])->map(fn ($key, $i) => (object) ['id' => 'any-'.$i, 'source_key' => $key]);
        $campaign = (object) ['id' => 'any-country-any-industry', 'industry' => null, 'normalized_industry_key' => null,
            'country_code' => null, 'region' => null, 'city' => null,
            'configuration' => json_encode(['limits' => ['partitions' => 3, 'provider_requests' => 9]])];
        $tasks = app(LeadDiscoverySourcePlanner::class)->plan($campaign, $sources);
        self::assertLessThanOrEqual(9, count($tasks));
        self::assertGreaterThan(0, count($tasks));
        foreach (['wikidata', 'gleif'] as $sourceKey) {
            $sourcePartitions = array_filter($tasks, fn ($task) => $task['source']->source_key === $sourceKey);
            self::assertLessThanOrEqual(3, count(array_unique(array_map(fn ($task) => $task['partition']['key'], $sourcePartitions))));
        }
        self::assertContains('IN', array_map(fn ($task) => $task['partition']['country_code'], array_filter($tasks, fn ($task) => $task['source']->source_key === 'india_ogd_mca')));
    }

    public function test_country_catalog_contains_all_assigned_iso_alpha_two_codes(): void
    {
        $catalog = app(\App\LeadDiscovery\LeadDiscoveryCountryCatalog::class);
        self::assertCount(249, $catalog->all());
        foreach (['IN', 'AE', 'SA', 'JP', 'BR', 'ZA', 'NO', 'IS'] as $code) self::assertSame($code, $catalog->normalize($code));
        foreach (['ZZ', 'ZZZ', '1N', 'IND'] as $invalid) self::assertNull($catalog->normalize($invalid));
    }

    private function query(string $industryKey): DiscoveryQuery
    {
        return new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'clinic', 'clinic hospital', 10, null,
            ['key' => 'AE:DU:Dubai', 'country_code' => 'AE', 'city' => 'Dubai', 'region' => 'Dubai', 'bbox' => [25.0, 55.0, 25.2, 55.4]], $industryKey);
    }

    private function approvedSource(): array
    {
        return ['source_enabled' => true, 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'storage_rights_confirmed' => true, 'storage_rights_reference' => 'synthetic-license-reference', 'license_or_authorization' => 'Synthetic source terms'];
    }
}
