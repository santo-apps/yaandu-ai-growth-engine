<?php

namespace Tests\Unit;

use App\LeadDiscovery\DiscoveryQuery;
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
        self::assertSame('© OpenStreetMap contributors', $page->candidates[0]['attribution']['attribution_text']);
        self::assertTrue($page->exhausted);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'overpass-api.de')
            && str_contains(urldecode($request->body()), '25,55,25.2,55.4') && str_contains(urldecode($request->body()), 'out center tags 10;'));
    }

    public function test_wikidata_uses_bounded_country_sparql_and_maps_qid_and_cc0(): void
    {
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => [[
            'item' => ['value' => 'https://www.wikidata.org/entity/Q123'], 'itemLabel' => ['value' => 'Synthetic Company'],
            'website' => ['value' => 'https://company.example'], 'industryLabel' => ['value' => 'Healthcare'], 'cityLabel' => ['value' => 'Dubai'],
        ]]]], 200)]);
        $query = new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'all', '', 10, null, ['country_code' => 'AE']);
        $page = app(WikidataLeadSourceAdapter::class)->search($query, $this->approvedSource());
        self::assertSame('Q123', $page->candidates[0]['wikidata_qid']);
        self::assertSame('CC0 1.0', $page->candidates[0]['attribution']['license_name']);
        self::assertTrue($page->exhausted);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request['query'], 'wdt:P297 "AE"')
            && str_contains((string) $request['query'], 'LIMIT 10') && str_contains((string) $request['query'], 'OFFSET 0'));
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

    public function test_custom_industry_and_valid_unconfigured_country_use_global_sources_without_osm_or_mca_failure(): void
    {
        $sources = collect(['osm_overpass', 'wikidata', 'india_ogd_mca', 'gleif'])->map(fn ($key, $i) => (object) ['id' => 's'.$i, 'source_key' => $key]);
        $campaign = (object) ['id' => 'custom-industry-sa', 'industry' => 'Marine Engineering', 'normalized_industry_key' => null,
            'country_code' => 'SA', 'region' => null, 'city' => 'Jeddah',
            'configuration' => json_encode(['limits' => ['partitions' => 4, 'provider_requests' => 10]])];
        $tasks = app(LeadDiscoverySourcePlanner::class)->plan($campaign, $sources);
        self::assertLessThanOrEqual(10, count($tasks));
        self::assertNotEmpty($tasks);
        self::assertNotContains('india_ogd_mca', array_map(fn ($task) => $task['source']->source_key, $tasks));
        self::assertNotContains('osm_overpass', array_map(fn ($task) => $task['source']->source_key, $tasks), 'OSM has no safe configured Saudi city bbox, but other providers remain usable.');
        self::assertContains('wikidata', array_map(fn ($task) => $task['source']->source_key, $tasks));
        self::assertContains('gleif', array_map(fn ($task) => $task['source']->source_key, $tasks));
        self::assertSame(['SA'], array_values(array_unique(array_map(fn ($task) => $task['partition']['country_code'], $tasks))));
        self::assertContains('Marine Engineering', array_column(array_column($tasks, 'query'), 'query'));
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
