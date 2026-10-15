<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private function tenantOwner(): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Discovery owner', 'slug' => 'discovery-owner-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $owner = User::create(['name' => 'Discovery owner', 'email' => 'discovery-owner-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password')]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        return [$tenant, $owner];
    }
}
