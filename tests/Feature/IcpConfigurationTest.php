<?php

namespace Tests\Feature;

use App\LeadScoring\LeadEvidenceBuilder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class IcpConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_versioned_draft_and_member_cannot_read_it(): void
    {
        [$tenant, $owner] = $this->workspace('icp-config-owner', 'owner');
        Sanctum::actingAs($owner);
        $draft = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations', $this->configuration())->assertCreated()
            ->assertJsonPath('version', 1)->assertJsonPath('status', 'draft')->json();

        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'icp_configuration.draft_created', 'subject_id' => $draft['id']]);
        [$memberTenant, $member] = $this->workspace('icp-config-member', 'member');
        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $memberTenant->id)->getJson('/api/v1/icp-configurations')->assertForbidden();
    }

    public function test_configuration_ids_cannot_be_read_updated_or_activated_across_tenants(): void
    {
        [$tenantA, $ownerA] = $this->workspace('icp-isolation-a', 'owner');
        Sanctum::actingAs($ownerA);
        $draft = $this->withHeader('X-Tenant-ID', $tenantA->id)->postJson('/api/v1/icp-configurations', $this->configuration())->assertCreated()->json();

        [$tenantB, $ownerB] = $this->workspace('icp-isolation-b', 'owner');
        Sanctum::actingAs($ownerB);
        $this->withHeader('X-Tenant-ID', $tenantB->id)->getJson('/api/v1/icp-configurations')->assertOk()->assertJsonPath('versions', []);
        $this->withHeader('X-Tenant-ID', $tenantB->id)->putJson('/api/v1/icp-configurations/'.$draft['id'], $this->configuration())->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenantB->id)->postJson('/api/v1/icp-configurations/'.$draft['id'].'/activate')->assertNotFound();
        self::assertDatabaseHas('tenant_icp_configurations', ['tenant_id' => $tenantA->id, 'id' => $draft['id'], 'status' => 'draft']);
    }

    public function test_activation_requires_explicit_geography_industry_and_active_service_fit(): void
    {
        [$tenant, $owner] = $this->workspace('icp-config-activation', 'owner');
        Sanctum::actingAs($owner);
        $data = $this->configuration();
        $data['geography']['target_countries'] = [];
        $draft = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations', $data)->assertCreated()->json();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations/'.$draft['id'].'/activate')->assertUnprocessable();

        $data = $this->configuration();
        $draft = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations', $data)->assertCreated()->json();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations/'.$draft['id'].'/activate')->assertUnprocessable();
        self::assertDatabaseHas('tenant_icp_configurations', ['tenant_id' => $tenant->id, 'id' => $draft['id'], 'status' => 'draft']);
    }

    public function test_owner_can_activate_a_valid_version_and_activation_is_audited(): void
    {
        [$tenant, $owner] = $this->workspace('icp-config-valid', 'owner');
        DB::table('tenant_services')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sku' => 'YND-WEB-PERF',
            'canonical_service_key' => 'website_modernization',
            'name' => 'Website modernization', 'description' => 'Configured test service', 'unit_price' => '100.00', 'currency' => 'INR',
            'active' => true, 'commercial_model' => 'FIXED_PRICE', 'unit' => 'project', 'approved_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_services')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sku' => 'unmapped-taxonomy-sku', 'canonical_service_key' => null,
                'name' => 'Unmapped', 'unit_price' => '100.00', 'currency' => 'INR', 'active' => true, 'approved_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sku' => 'inactive-ai', 'canonical_service_key' => 'ai_agents',
                'name' => 'Inactive AI', 'unit_price' => '100.00', 'currency' => 'INR', 'active' => false, 'approved_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sku' => 'unapproved-seo', 'canonical_service_key' => 'seo',
                'name' => 'Unapproved SEO', 'unit_price' => '100.00', 'currency' => 'INR', 'active' => true, 'approved_by' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Sanctum::actingAs($owner);
        $configuration = $this->configuration();
        $configuration['service_fit']['service_keys'] = ['website_modernization'];
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/icp-configurations')->assertOk()
            ->assertJsonPath('available_service_keys.0', 'website_modernization')
            ->assertJsonPath('available_service_capabilities.0.key', 'website_modernization')
            ->assertJsonPath('available_service_capabilities.0.label', 'Website modernization');
        $draft = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations', $configuration)->assertCreated()->json();
        $active = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations/'.$draft['id'].'/activate')->assertOk()
            ->assertJsonPath('status', 'active')->assertJsonPath('activated_by', $owner->id)->json();

        self::assertNotNull($active['activated_at']);
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'icp_configuration.activated', 'subject_id' => $draft['id']]);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations/'.$draft['id'].'/deactivate')->assertOk()->assertJsonPath('status', 'inactive');
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'icp_configuration.deactivated', 'subject_id' => $draft['id']]);
    }

    public function test_active_version_drives_explicit_geography_and_industry_scoring_without_defaults(): void
    {
        [$tenant, $owner] = $this->workspace('icp-config-scoring', 'owner');
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Example', 'industry' => 'Healthcare', 'location' => 'India',
            'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $config = $this->configuration();
        $config['geography']['target_countries'] = ['India'];
        $config['geography']['target_locations'] = ['India'];
        $config['organization_suitability']['target_industries'] = ['Healthcare'];
        DB::table('tenant_icp_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'version' => 1, 'status' => 'active',
            'configuration' => json_encode($config), 'created_by' => $owner->id, 'activated_by' => $owner->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $evidence = app(LeadEvidenceBuilder::class)->build($tenant->id, $companyId);

        self::assertSame('positive', $evidence['icp_fit']['status']);
        self::assertSame('positive', $evidence['relevant_industry']['status']);
    }

    public function test_recommendation_evidence_without_a_validated_opportunity_is_excluded_from_scoring(): void
    {
        [$tenant] = $this->workspace('recommendation-not-a-score-signal', 'owner');
        $companyId = (string) Str::uuid();
        $insightId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Example', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_insights')->insert(['id' => $insightId, 'tenant_id' => $tenant->id, 'company_id' => $companyId,
            'kind' => 'service_recommendation', 'statement' => 'Speculative website modernization recommendation.', 'confidence' => 0.99,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_evidence')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'lead_insight_id' => $insightId,
            'evidence_type' => 'website_page', 'source_url' => 'https://example.test/', 'excerpt' => 'General company description only.',
            'content_hash' => hash('sha256', 'General company description only.'), 'observed_at' => now(), 'confidence' => 0.99,
            'created_at' => now(), 'updated_at' => now()]);

        $evidence = app(LeadEvidenceBuilder::class)->build($tenant->id, $companyId);
        $score = (new \App\LeadScoring\ScoringRuleEvaluator())->score($evidence);

        self::assertSame('unknown', $evidence['strong_business_fit']['status']);
        self::assertSame('UNKNOWN', $score['components']['strong_business_fit']['status']);
        self::assertSame(0, $score['components']['strong_business_fit']['points']);
        self::assertNull($score['score']);
        self::assertSame('insufficient_evidence', $score['evaluation_status']);
    }

    public function test_country_icp_matches_public_company_locations_using_common_country_names_and_codes(): void
    {
        [$tenant, $owner] = $this->workspace('icp-country-location-matching', 'owner');
        $indiaCompany = (string) Str::uuid(); $uaeCompany = (string) Str::uuid();
        foreach ([[$indiaCompany, 'Textile & Apparel', 'Salem, IN'], [$uaeCompany, 'Manufacturing', 'Sharjah, UAE']] as [$id, $industry, $location]) {
            DB::table('companies')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'name' => $id, 'industry' => $industry, 'location' => $location,
                'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        }
        $config = $this->configuration();
        $config['geography']['target_countries'] = ['India', 'UAE'];
        $config['organization_suitability']['target_industries'] = ['Textile & Apparel', 'Manufacturing'];
        DB::table('tenant_icp_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'version' => 1, 'status' => 'active',
            'configuration' => json_encode($config), 'created_by' => $owner->id, 'activated_by' => $owner->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        self::assertSame('positive', app(LeadEvidenceBuilder::class)->build($tenant->id, $indiaCompany)['icp_fit']['status']);
        self::assertSame('positive', app(LeadEvidenceBuilder::class)->build($tenant->id, $uaeCompany)['icp_fit']['status']);
    }

    public function test_country_normalization_matches_uae_aliases_and_keeps_unknown_cities_unknown(): void
    {
        [$tenant, $owner] = $this->workspace('icp-country-aliases', 'owner');
        $locations = ['AE', 'UAE', 'United Arab Emirates', 'Abu Dhabi, AE', 'Dubai, AE', 'Sharjah, AE', 'Salem, IN', 'Bengaluru, IND', 'India', 'Paris, FR', 'Abu Dhabi'];
        $companyIds = [];
        foreach ($locations as $index => $location) {
            $id = (string) Str::uuid();
            $companyIds[$location] = $id;
            DB::table('companies')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'name' => 'Country case '.$index,
                'industry' => 'Healthcare', 'location' => $location, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        }
        $config = $this->configuration();
        $config['geography']['target_countries'] = ['UAE', 'India'];
        $config['geography']['target_locations'] = [];
        DB::table('tenant_icp_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'version' => 1, 'status' => 'active',
            'configuration' => json_encode($config), 'created_by' => $owner->id, 'activated_by' => $owner->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $builder = app(LeadEvidenceBuilder::class);
        foreach (['AE', 'UAE', 'United Arab Emirates', 'Abu Dhabi, AE', 'Dubai, AE', 'Sharjah, AE', 'Salem, IN', 'Bengaluru, IND', 'India'] as $location) {
            self::assertSame('positive', $builder->build($tenant->id, $companyIds[$location])['icp_fit']['status'], $location);
        }
        self::assertSame('negative', $builder->build($tenant->id, $companyIds['Paris, FR'])['icp_fit']['status']);
        self::assertSame('unknown', $builder->build($tenant->id, $companyIds['Abu Dhabi'])['icp_fit']['status']);
    }

    public function test_city_geography_criteria_are_matched_without_collapsing_unknown_locations(): void
    {
        [$tenant, $owner] = $this->workspace('icp-city-location-matching', 'owner');
        $ids = [];
        foreach (['Abu Dhabi, AE', 'Dubai, AE', 'Dubai'] as $index => $location) {
            $id = (string) Str::uuid(); $ids[$location] = $id;
            DB::table('companies')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'name' => 'City case '.$index,
                'industry' => 'Healthcare', 'location' => $location, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        }
        $config = $this->configuration();
        $config['geography']['target_countries'] = [];
        $config['geography']['target_locations'] = ['Abu Dhabi'];
        DB::table('tenant_icp_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'version' => 1, 'status' => 'active',
            'configuration' => json_encode($config), 'created_by' => $owner->id, 'activated_by' => $owner->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $builder = app(LeadEvidenceBuilder::class);
        self::assertSame('positive', $builder->build($tenant->id, $ids['Abu Dhabi, AE'])['icp_fit']['status']);
        self::assertSame('negative', $builder->build($tenant->id, $ids['Dubai, AE'])['icp_fit']['status']);
        self::assertSame('unknown', $builder->build($tenant->id, $ids['Dubai'])['icp_fit']['status']);
    }

    public function test_owner_approved_digital_opportunity_and_contact_categories_are_accepted(): void
    {
        [$tenant, $owner] = $this->workspace('icp-config-rich-categories', 'owner');
        Sanctum::actingAs($owner);
        $config = $this->configuration();
        $config['digital_opportunity']['evidence_types'] = [
            'website_modernization_need', 'ecommerce_enablement_migration', 'lead_capture_cro', 'whatsapp_customer_engagement_automation',
            'erp_workflow_automation', 'ai_process_automation', 'seo_content_discoverability', 'mobile_custom_software', 'cloud_devops_modernization',
        ];
        $config['commercial_contact_readiness']['evidence_requirements'] = [
            'public_company_contact_channel', 'contact_enquiry_form', 'public_business_email_phone', 'named_public_business_contact',
            'explicit_sales_contact_mechanism', 'source_and_timestamp_recorded',
        ];
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/icp-configurations', $config)->assertCreated()
            ->assertJsonPath('status', 'draft');
    }

    private function configuration(): array
    {
        return ['geography' => ['target_countries' => ['India'], 'target_locations' => []],
            'organization_suitability' => ['target_industries' => ['Healthcare'], 'business_types' => ['B2B operator'], 'scale_bands' => [], 'commercial_viability_criteria' => ['Public evidence of multi-location operations']],
            'digital_opportunity' => ['evidence_types' => ['website_modernization_need']], 'service_fit' => ['service_keys' => []],
            'commercial_contact_readiness' => ['evidence_requirements' => ['public_company_contact_path']]];
    }

    private function workspace(string $slug, string $role): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => $slug, 'email' => $slug.'@example.test', 'password' => bcrypt(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$tenant, $user];
    }
}
