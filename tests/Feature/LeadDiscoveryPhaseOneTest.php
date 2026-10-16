<?php

namespace Tests\Feature;

use App\Crawling\PublicAddressResolverInterface;
use App\Jobs\ScanWebsiteJob;
use App\Jobs\VerifyLeadDiscoveryCandidateJob;
use App\LeadDiscovery\LeadDiscoveryCandidateVerifier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadDiscoveryPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34']; }
        });
    }

    public function test_csv_campaign_stages_and_verifies_candidate_then_manually_promotes_into_existing_company_pipeline(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-a');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner);
        Queue::fake();

        $source = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Licensed Retail Export',
            'license_or_authorization' => 'Owner supplied licensed business dataset', 'allowed_collection_method' => 'licensed_csv_upload',
            'retention_policy' => 'Retain under workspace policy', 'attribution_requirement' => 'Source dataset name', 'enabled' => true,
        ])->assertCreated()->json();
        $campaign = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'India E-commerce & Retail Discovery', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN',
            'target_leads' => 5000, 'limits' => ['source_requests' => 25, 'candidates' => 25, 'processing' => 25, 'enrichment' => 25, 'runtime_seconds' => 300, 'http_requests' => 3, 'ai_calls' => 0, 'provider_spend_minor' => 0],
        ])->assertCreated()->assertJsonPath('auto_promotion_enabled', false)->json();

        $csv = "company_name,website,industry,country_code,city,source_url,business_email,business_phone\nNorthstar Retail,https://www.northstar.example/about,E-commerce & Retail,IN,Mumbai,https://dataset.example/record/1,info@northstar.example,+91 2212345678\n";
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('licensed-retail.csv', $csv), 'idempotency_key' => 'lead-discovery-import-1',
        ])->assertAccepted()->assertJsonPath('candidate_count', 1)->assertJsonPath('auto_promotion', false);
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('licensed-retail.csv', $csv), 'idempotency_key' => 'lead-discovery-import-1',
        ])->assertOk()->assertJsonPath('replayed', true);

        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->first();
        self::assertSame('discovered', $candidate->processing_status);
        self::assertSame('northstar.example', $candidate->normalized_domain);
        self::assertSame('country_match', $candidate->geographic_relevance, 'CSV Mumbai evidence does not imply a city-targeted campaign match.');
        self::assertSame('Mumbai', DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant->id)->where('candidate_id', $candidate->id)->where('field_name', 'city')->value('normalized_value'));
        $cityAttribution = json_decode((string) DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant->id)->where('candidate_id', $candidate->id)->where('field_name', 'city')->value('attribution'), true);
        self::assertSame('city', $cityAttribution['geographic_evidence_field']);
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant->id)->count(), 'Import keeps candidates out of the company master.');
        self::assertGreaterThanOrEqual(5, DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant->id)->where('candidate_id', $candidate->id)->count());
        Queue::assertPushed(VerifyLeadDiscoveryCandidateJob::class, 1);
        Queue::assertNotPushed(ScanWebsiteJob::class);

        Http::fake([
            'https://northstar.example/robots.txt' => Http::response('', 404),
            'https://northstar.example/' => Http::response('<html><head><title>Northstar Retail</title><meta property="og:site_name" content="Northstar Retail"></head><body><h1>Northstar Retail</h1><p>Contact info@northstar.example</p><a href="mailto:info@northstar.example">Email</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        (new VerifyLeadDiscoveryCandidateJob($tenant->id, $candidate->id))->handle(app(LeadDiscoveryCandidateVerifier::class));
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->first();
        self::assertSame('verified', $candidate->official_domain_status);
        self::assertSame('eligible', $candidate->eligibility_status);
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant->id)->count(), 'Verification still does not create companies.');

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/candidates/'.$candidate->id.'/promote', [])
            ->assertCreated()->assertJsonPath('company.normalized_domain', 'northstar.example');
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->where('normalized_domain', 'northstar.example')->first();
        self::assertSame('new', $company->status, 'Promotion does not auto-select a sales decision.');
        self::assertSame(1, DB::table('company_websites')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->count());
        self::assertSame(1, DB::table('contacts')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->count());
        self::assertSame(2, DB::table('contact_methods')->where('tenant_id', $tenant->id)->count());
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'lead_discovery_candidate_promoted', 'subject_id' => $candidate->id]);
        Queue::assertPushed(ScanWebsiteJob::class, 1);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'])
            ->assertOk()->assertJsonPath('counts.discovered', 1)->assertJsonPath('counts.promoted', 1)->assertJsonPath('contacts_enriched', 1);
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
    }

    public function test_source_configuration_and_candidate_relationships_are_tenant_scoped_and_role_protected(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-owner');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        [$otherTenant] = $this->tenantAndUser('lead-discovery-other');
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Unlicensed', 'license_or_authorization' => '',
            'allowed_collection_method' => 'csv', 'enabled' => true,
        ])->assertUnprocessable();

        $owner->tenants()->attach($otherTenant->id, ['role' => 'owner', 'status' => 'active']);
        $sourceId = (string) Str::uuid();
        DB::table('lead_discovery_sources')->insert(['id' => $sourceId, 'tenant_id' => $otherTenant->id, 'source_key' => 'csv_upload', 'provider_name' => 'Other tenant',
            'source_type' => 'licensed_csv', 'authorization_status' => 'authorized', 'allowed_collection_method' => 'csv', 'configuration' => '{}', 'enabled' => true,
            'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeader('X-Tenant-ID', $tenant->id)->patchJson('/api/v1/lead-discovery/sources/'.$sourceId, ['enabled' => false])->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/lead-discovery/candidates/'.(string) Str::uuid())->assertNotFound();
    }

    public function test_only_owner_or_admin_can_configure_a_source(): void
    {
        [$tenant, $user] = $this->tenantAndUser('lead-discovery-member');
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Licensed source', 'license_or_authorization' => 'Documented license',
            'allowed_collection_method' => 'licensed_csv_upload', 'enabled' => true,
        ])->assertForbidden();
        self::assertSame(0, DB::table('lead_discovery_sources')->count());
    }

    public function test_campaign_pause_resume_cancel_controls_are_tenant_scoped_and_idempotently_requeue_pending_work(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-controls');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner);
        $campaign = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Control test', 'industry' => 'Retail', 'country_code' => 'IN',
        ])->assertCreated()->json();
        DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaign['id'])->update(['status' => 'running']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/pause', [])->assertOk()->assertJsonPath('status', 'paused');
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/resume', [])->assertOk()->assertJsonPath('status', 'running');
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/cancel', [])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'lead_discovery_campaign_cancelled', 'subject_id' => $campaign['id']]);
    }

    public function test_verified_duplicate_can_be_human_reused_without_overwriting_existing_company(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-reuse');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $existingCompanyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $existingCompanyId, 'tenant_id' => $tenant->id, 'name' => 'Existing Northstar',
            'normalized_domain' => 'northstar.example', 'industry' => 'Existing sector', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($owner); Queue::fake();
        $source = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Licensed source', 'license_or_authorization' => 'Dataset rights recorded',
            'allowed_collection_method' => 'licensed_csv_upload', 'enabled' => true,
        ])->assertCreated()->json();
        $campaign = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Retail campaign', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN',
        ])->assertCreated()->json();
        $csv = "company_name,website,industry,country_code\nNorthstar Retail,https://northstar.example,E-commerce & Retail,IN\n";
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('licensed.csv', $csv),
        ])->assertAccepted();
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->first();
        self::assertSame($existingCompanyId, $candidate->matched_company_id);
        self::assertSame(1, DB::table('companies')->where('tenant_id', $tenant->id)->count());
        Http::fake(['https://northstar.example/robots.txt' => Http::response('', 404),
            'https://northstar.example/' => Http::response('<html><head><title>Northstar Retail</title></head><body><h1>Northstar Retail</h1></body></html>', 200, ['Content-Type' => 'text/html'])]);
        (new VerifyLeadDiscoveryCandidateJob($tenant->id, $candidate->id))->handle(app(LeadDiscoveryCandidateVerifier::class));
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->first();
        self::assertSame('verified', $candidate->official_domain_status);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/candidates/'.$candidate->id.'/promote', [])->assertCreated()
            ->assertJsonPath('company.id', $existingCompanyId);
        self::assertSame(1, DB::table('companies')->where('tenant_id', $tenant->id)->count());
        self::assertSame('Existing Northstar', DB::table('companies')->where('tenant_id', $tenant->id)->where('id', $existingCompanyId)->value('name'));
    }

    public function test_source_revocation_prevents_queued_website_verification(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-revoked');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner); Queue::fake();
        $source = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Licensed source', 'license_or_authorization' => 'Dataset rights recorded',
            'allowed_collection_method' => 'licensed_csv_upload', 'enabled' => true,
        ])->assertCreated()->json();
        $campaign = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Retail campaign', 'industry' => 'Retail', 'country_code' => 'IN',
        ])->assertCreated()->json();
        $csv = "company_name,website,industry,country_code\nNorthstar Retail,https://northstar.example,Retail,IN\n";
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('licensed.csv', $csv),
        ])->assertAccepted();
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->first();
        DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->where('id', $source['id'])->update(['enabled' => false, 'authorization_status' => 'revoked']);
        Http::preventStrayRequests();
        (new VerifyLeadDiscoveryCandidateJob($tenant->id, $candidate->id))->handle(app(LeadDiscoveryCandidateVerifier::class));
        Http::assertNothingSent();
        self::assertSame('rejected', DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->value('eligibility_status'));
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant->id)->count());
    }

    public function test_campaign_candidate_and_processing_budgets_are_cumulative_across_imports(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('lead-discovery-budgets');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner); Queue::fake();
        $source = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'csv_upload', 'provider_name' => 'Licensed source', 'license_or_authorization' => 'Dataset rights recorded',
            'allowed_collection_method' => 'licensed_csv_upload', 'enabled' => true,
        ])->assertCreated()->json();
        $campaign = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Bounded campaign', 'industry' => 'Retail', 'country_code' => 'IN',
            'limits' => ['source_requests' => 5, 'candidates' => 1, 'processing' => 1, 'enrichment' => 0,
                'runtime_seconds' => 60, 'http_requests' => 3, 'ai_calls' => 0, 'provider_spend_minor' => 0],
        ])->assertCreated()->json();
        $first = "company_name,website,industry,country_code\nNorthstar Retail,https://northstar.example,Retail,IN\n";
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('first.csv', $first),
        ])->assertAccepted();
        $second = "company_name,website,industry,country_code\nSecond Retail,https://second.example,Retail,IN\n";
        $this->withHeader('X-Tenant-ID', $tenant->id)->post('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/import', [
            'source_id' => $source['id'], 'csv' => UploadedFile::fake()->createWithContent('second.csv', $second),
        ])->assertStatus(429);
        self::assertSame(1, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign['id'])->count());
        Queue::assertPushed(VerifyLeadDiscoveryCandidateJob::class, 1);
    }

    private function tenantAndUser(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => 'Lead Discovery User', 'email' => $slug.'@example.test', 'password' => bcrypt('password')]);
        return [$tenant, $user];
    }
}
