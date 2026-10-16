<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadDiscoveryPhaseTwoBControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lead_discovery.enabled' => true, 'lead_discovery.live_enabled' => false]);
    }

    public function test_campaign_filters_accept_industry_only_country_only_both_or_neither(): void
    {
        [$tenant, $owner] = $this->tenantOwner();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $cases = [
            ['industry' => 'E-commerce & Retail', 'country_code' => 'IN'],
            ['industry' => null, 'country_code' => 'IN'],
            ['industry' => 'IT & Software', 'country_code' => null],
            ['industry' => null, 'country_code' => null],
            ['industry' => 'Marine Engineering', 'country_code' => 'SA'],
            ['industry' => 'Pet Care', 'country_code' => 'JP'],
            ['industry' => 'EV Charging Infrastructure', 'country_code' => 'BR'],
            ['industry' => 'Jewellery Manufacturing', 'country_code' => 'ZA'],
        ];
        foreach ($cases as $index => $filters) {
            $campaign = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
                'name' => 'Optional filters '.$index, ...$filters, 'target_leads' => 5,
            ])->assertCreated()->json();
            self::assertSame($filters['industry'], $campaign['industry']);
            self::assertSame($filters['country_code'], $campaign['country_code']);
            self::assertSame($filters['industry'] === null ? null : (in_array($filters['industry'], ['E-commerce & Retail', 'IT & Software'], true)
                ? app(\App\LeadDiscovery\LeadDiscoveryIndustryTaxonomy::class)->keyFor($filters['industry']) : null), $campaign['normalized_industry_key']);
            self::assertFalse($campaign['auto_promotion_enabled']);
        }
        foreach (['IN', 'AE', 'SA', 'JP', 'BR', 'ZA', 'NO', 'IS'] as $index => $code) {
            $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
                'name' => 'Country '.$code, 'country_code' => $code, 'industry' => null, 'target_leads' => 5,
            ])->assertCreated()->assertJsonPath('country_code', $code);
        }
        foreach (['ZZZ', '1N', 'IND'] as $index => $code) {
            $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
                'name' => 'Invalid country '.$index, 'country_code' => $code, 'target_leads' => 5,
            ])->assertUnprocessable()->assertJsonValidationErrors('country_code');
        }
    }

    public function test_catalog_industries_and_countries_are_tenant_scoped_and_google_maps_is_not_selectable(): void
    {
        [$tenant, $owner] = $this->tenantOwner();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $catalog = $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/source-catalog')->assertOk()->json('data');
        $keys = array_column($catalog, 'source_key');
        self::assertContains('osm_overpass', $keys);
        self::assertContains('wikidata', $keys);
        self::assertContains('india_ogd_mca', $keys);
        self::assertContains('gleif', $keys);
        self::assertContains('google_maps', $keys);
        $google = collect($catalog)->firstWhere('source_key', 'google_maps');
        self::assertFalse($google['selectable']);
        self::assertSame('PROHIBITED_FOR_BULK_STORAGE', $google['governance_class']);
        self::assertNotContains('google_maps', array_filter($keys, fn ($key) => collect($catalog)->firstWhere('source_key', $key)['selectable']));
        $industries = $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/industries')->assertOk()->json('data');
        self::assertContains('Healthcare', array_column($industries, 'label'));
        $countries = $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/countries')->assertOk()->json('data');
        self::assertContains('AE', array_column($countries, 'code'));
        self::assertCount(249, $countries);
        self::assertContains('SA', array_column($countries, 'code'));
        self::assertContains('IS', array_column($countries, 'code'));
    }

    public function test_documented_open_licenses_confirm_storage_without_brave_style_agreement_fields(): void
    {
        [$tenant, $owner] = $this->tenantOwner();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        foreach (['osm_overpass', 'wikidata', 'gleif'] as $sourceKey) {
            $source = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', [
                'source_key' => $sourceKey, 'enabled' => false,
            ])->assertCreated()->json();
            self::assertSame('confirmed', $source['storage_rights_status']);
            self::assertTrue($source['storage_rights_confirmed']);
        }
        $brave = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'brave_search', 'license_or_authorization' => 'Terms reviewed', 'enabled' => false,
        ])->assertCreated()->json();
        self::assertSame('not_confirmed', $brave['storage_rights_status']);
        self::assertFalse($brave['storage_rights_confirmed']);
    }

    public function test_candidate_api_returns_separate_geography_provenance_and_filters_unknown_candidates(): void
    {
        [$tenant, $owner] = $this->tenantOwner();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $source = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'osm_overpass', 'enabled' => false,
        ])->assertCreated()->json();
        $campaign = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Chennai retail', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN',
            'region' => 'Tamil Nadu', 'city' => 'Chennai', 'target_leads' => 5,
        ])->assertCreated()->json();
        $runId = (string) Str::uuid();
        $candidateId = (string) Str::uuid();
        $now = now();
        DB::table('lead_discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign['id'],
            'source_id' => $source['id'], 'run_status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('lead_discovery_candidates')->insert(['id' => $candidateId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign['id'],
            'run_id' => $runId, 'source_id' => $source['id'], 'company_name' => 'Chennai Retailer', 'normalized_name' => 'chennai retailer',
            'official_domain_status' => 'unverified', 'country_code' => 'IN', 'region' => 'Tamil Nadu', 'city' => 'Chennai',
            'geographic_relevance' => 'city_match', 'processing_status' => 'discovered', 'validation_status' => 'pending',
            'promotion_status' => 'not_promoted', 'eligibility_status' => 'needs_review', 'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['country_code', 'India', 'IN', 'addr:country'], ['region', 'Tamil Nadu', 'Tamil Nadu', 'addr:state'], ['city', 'Chennai', 'Chennai', 'addr:city']] as [$field, $raw, $normalized, $marker]) {
            DB::table('lead_discovery_candidate_evidence')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'candidate_id' => $candidateId, 'campaign_id' => $campaign['id'], 'source_id' => $source['id'], 'field_name' => $field,
                'evidence_type' => 'open_source_record', 'raw_value' => $raw, 'normalized_value' => $normalized, 'confidence_score' => 0.9,
                'collected_at' => $now, 'attribution' => json_encode(['source_key' => 'osm_overpass', 'geographic_evidence_field' => $marker]),
                'created_at' => $now, 'updated_at' => $now]);
        }
        $unknownId = (string) Str::uuid();
        DB::table('lead_discovery_candidates')->insert(['id' => $unknownId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign['id'],
            'run_id' => $runId, 'source_id' => $source['id'], 'company_name' => 'Unknown location candidate', 'normalized_name' => 'unknown location candidate',
            'official_domain_status' => 'unknown', 'geographic_relevance' => 'location_unknown', 'processing_status' => 'needs_review',
            'validation_status' => 'unknown', 'promotion_status' => 'not_promoted', 'eligibility_status' => 'needs_review',
            'discovered_at' => $now, 'created_at' => $now->copy()->addSecond(), 'updated_at' => $now]);

        $response = $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates?campaign_id='.$campaign['id'])
            ->assertOk()->assertJsonPath('data.0.geographic_relevance.classification', 'city_match')
            ->assertJsonPath('data.0.geographic_relevance.requested_location.city', 'Chennai')
            ->assertJsonPath('data.0.geographic_relevance.evidence_location.city', 'Chennai');
        self::assertSame($candidateId, $response->json('data.0.id'));
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates?campaign_id='.$campaign['id'].'&geographic_relevance=location_unknown')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unknownId)
            ->assertJsonPath('data.0.geographic_relevance.classification', 'location_unknown');
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates/'.$candidateId)
            ->assertOk()->assertJsonPath('candidate.geographic_relevance.classification', 'city_match');
    }

    public function test_candidate_geography_api_remains_tenant_scoped(): void
    {
        [$tenant, $owner] = $this->tenantOwner();
        [$otherTenant, $otherOwner] = $this->tenantOwner();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $source = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', ['source_key' => 'osm_overpass', 'enabled' => false])->assertCreated()->json();
        $campaign = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Tenant scope', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN', 'target_leads' => 5,
        ])->assertCreated()->json();
        $runId = (string) Str::uuid(); $candidateId = (string) Str::uuid(); $now = now();
        DB::table('lead_discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign['id'], 'source_id' => $source['id'], 'run_status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('lead_discovery_candidates')->insert(['id' => $candidateId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign['id'], 'run_id' => $runId, 'source_id' => $source['id'], 'company_name' => 'Private candidate', 'normalized_name' => 'private candidate', 'official_domain_status' => 'unknown', 'geographic_relevance' => null, 'processing_status' => 'discovered', 'validation_status' => 'unknown', 'promotion_status' => 'not_promoted', 'eligibility_status' => 'needs_review', 'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        Sanctum::actingAs($otherOwner);
        $otherHeaders = ['X-Tenant-ID' => $otherTenant->id];
        $otherSource = $this->withHeaders($otherHeaders)->postJson('/api/v1/lead-discovery/sources', ['source_key' => 'osm_overpass', 'enabled' => false])->assertCreated()->json();
        $otherCampaign = $this->withHeaders($otherHeaders)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Other tenant', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN', 'target_leads' => 5,
        ])->assertCreated()->json();
        $otherRunId = (string) Str::uuid(); $otherCandidateId = (string) Str::uuid();
        DB::table('lead_discovery_runs')->insert(['id' => $otherRunId, 'tenant_id' => $otherTenant->id, 'campaign_id' => $otherCampaign['id'], 'source_id' => $otherSource['id'], 'run_status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('lead_discovery_candidates')->insert(['id' => $otherCandidateId, 'tenant_id' => $otherTenant->id, 'campaign_id' => $otherCampaign['id'], 'run_id' => $otherRunId, 'source_id' => $otherSource['id'], 'company_name' => 'Other tenant candidate', 'normalized_name' => 'other tenant candidate', 'official_domain_status' => 'unknown', 'geographic_relevance' => null, 'processing_status' => 'discovered', 'validation_status' => 'unknown', 'promotion_status' => 'not_promoted', 'eligibility_status' => 'needs_review', 'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        Sanctum::actingAs($owner);
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates?geographic_relevance=location_unknown')
            ->assertOk()->assertJsonPath('data.0.id', $candidateId)->assertJsonPath('data.0.geographic_relevance.classification', 'location_unknown');
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates?campaign_id='.$otherCampaign['id'])->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates/'.(string) Str::uuid())->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/lead-discovery/candidates/'.$otherCandidateId)->assertNotFound();
    }

    private function tenantOwner(): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Discovery owner', 'slug' => 'discovery-owner-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $owner = User::create(['name' => 'Discovery owner', 'email' => 'discovery-owner-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password')]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        return [$tenant, $owner];
    }
}
