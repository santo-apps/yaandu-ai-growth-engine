<?php

namespace Tests\Feature;

use App\Agents\AgentContext;
use App\Agents\LeadScoringAgent;
use App\LeadScoring\LeadEvidenceBuilder;
use App\LeadScoring\ScoringRuleEvaluator;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\WebsiteIntelligence\PublicContactExtractor;
use App\Contacts\ContactMethodValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseOneWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_tenant_membership_cannot_access_tenant_api(): void
    {
        [$tenant, $user] = $this->tenantAndUser('inactive-membership');
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'suspended']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/companies')->assertForbidden();
    }

    public function test_horizon_access_is_limited_to_active_tenant_owners_and_admins(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('horizon-owner');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        [, $member] = $this->tenantAndUser('horizon-member');
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);

        self::assertTrue(Gate::forUser($owner)->allows('viewHorizon'));
        self::assertFalse(Gate::forUser($member)->allows('viewHorizon'));
    }

    public function test_company_intelligence_is_not_visible_across_tenants(): void
    {
        [$tenant, $user] = $this->tenantAndUser('tenant-a');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $company = $this->company($otherTenant->id, 'Private company');
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/companies/'.$company->id.'/intelligence')
            ->assertNotFound();
    }

    public function test_scoring_cannot_be_queued_for_another_tenants_company(): void
    {
        [$tenant, $user] = $this->tenantAndUser('score-tenant-a');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Tenant B', 'slug' => 'score-tenant-b', 'status' => 'active']);
        $company = $this->company($otherTenant->id, 'Foreign company');
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/companies/'.$company->id.'/score')->assertNotFound();
        self::assertSame(0, DB::table('agent_runs')->where('tenant_id', $tenant->id)->count());
    }

    public function test_discovery_endpoint_persists_run_before_queueing(): void
    {
        [$tenant, $user] = $this->tenantAndUser('discovery-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        Queue::fake();

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/discovery-runs', [
            'candidates' => [['name' => 'Example Business', 'website' => 'https://example.test', 'source' => 'user_seed']],
        ]);

        $response->assertAccepted()->assertJsonPath('agent_key', 'DiscoveryAgent');
        $this->assertDatabaseHas('agent_runs', ['id' => $response->json('id'), 'tenant_id' => $tenant->id, 'status' => 'queued']);
        Queue::assertPushed(\App\Jobs\RunAgentJob::class);
    }

    public function test_website_scan_endpoint_persists_scan_and_run_before_queueing(): void
    {
        [$tenant, $user] = $this->tenantAndUser('scan-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = $this->company($tenant->id, 'Scan Company');
        $websiteId = (string) Str::uuid();
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'url' => 'https://scan.test', 'host' => 'scan.test', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        Queue::fake();

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/websites/'.$websiteId.'/scan', ['max_pages' => 5, 'max_depth' => 1]);

        $response->assertAccepted()->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('website_scans', ['id' => $response->json('scan_id'), 'tenant_id' => $tenant->id, 'status' => 'queued']);
        $this->assertDatabaseHas('agent_runs', ['id' => $response->json('agent_run_id'), 'tenant_id' => $tenant->id, 'status' => 'queued']);
        Queue::assertPushed(\App\Jobs\ScanWebsiteJob::class);
    }

    public function test_icp_and_score_rules_are_tenant_configurable(): void
    {
        [$tenant, $user] = $this->tenantAndUser('icp-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson('/api/v1/scoring-rules', [
            'rules' => ['outdated_website' => 25],
            'icp' => ['industries' => ['Technology'], 'locations' => ['Toronto'], 'keywords' => ['cloud']],
        ])->assertOk()->assertJsonPath('rules.outdated_website', 25)->assertJsonPath('icp.industries.0', 'Technology');

        $settings = json_decode(DB::table('tenants')->where('id', $tenant->id)->value('settings'), true);
        self::assertSame('cloud', $settings['scoring']['icp']['keywords'][0]);
    }

    public function test_ai_configuration_lists_safe_defaults_and_rejects_secret_parameters(): void
    {
        [$tenant, $user] = $this->tenantAndUser('ai-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/ai-configurations')
            ->assertOk()->assertJsonFragment(['task_key' => 'website_reasoning', 'provider' => 'anthropic'])
            ->assertJsonMissing(['key' => '']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/ai-configurations', [
            'task_key' => 'website_reasoning', 'provider' => 'openai', 'model' => 'safe-model', 'parameters' => ['api_key' => 'must-not-store'],
        ])->assertUnprocessable();

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/ai-configurations', [
            'task_key' => 'website_reasoning', 'provider' => 'openai', 'model' => 'safe-model', 'enabled' => true,
            'parameters' => ['temperature' => 0.4, 'max_output_tokens' => 2048],
        ])->assertCreated();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/ai-configurations', [
            'task_key' => 'proposal_generation', 'provider' => 'anthropic', 'model' => 'safe-proposal-model', 'enabled' => true,
        ])->assertCreated()->assertJsonPath('task_key', 'proposal_generation');
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/ai-configurations')
            ->assertOk()->assertJsonFragment(['task_key' => 'website_reasoning', 'provider' => 'openai', 'model' => 'safe-model', 'parameters' => ['temperature' => 0.4, 'max_output_tokens' => 2048]]);
    }

    public function test_deterministic_provider_is_selectable_only_when_local_acceptance_is_explicitly_enabled(): void
    {
        [$tenant, $user] = $this->tenantAndUser('ai-local-acceptance');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-ID' => $tenant->id];

        config(['ai.local_acceptance.enabled' => false]);
        $this->withHeaders($headers)->postJson('/api/v1/ai-configurations', [
            'task_key' => 'content_generation', 'provider' => 'deterministic', 'model' => 'local-acceptance-v1', 'enabled' => true,
        ])->assertUnprocessable();
        $this->withHeaders($headers)->getJson('/api/v1/ai-configurations')->assertOk()
            ->assertJsonPath('0.available_providers', ['openai', 'anthropic', 'gemini']);

        config(['ai.local_acceptance.enabled' => true]);
        $this->withHeaders($headers)->getJson('/api/v1/ai-configurations')->assertOk()
            ->assertJsonPath('0.available_providers', ['openai', 'anthropic', 'gemini', 'deterministic']);
        $this->withHeaders($headers)->postJson('/api/v1/ai-configurations', [
            'task_key' => 'content_generation', 'provider' => 'deterministic', 'model' => 'local-acceptance-v1', 'enabled' => true,
        ])->assertCreated()->assertJsonPath('provider', 'deterministic');
    }

    public function test_dashboard_summary_is_tenant_scoped_and_uses_latest_score(): void
    {
        [$tenant, $user] = $this->tenantAndUser('dashboard-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = $this->company($tenant->id, 'Dashboard Company');
        DB::table('lead_scores')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'score' => 80, 'components' => '{}', 'rule_version' => 1, 'scored_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/dashboard/summary')
            ->assertOk()->assertJsonPath('companies', 1)->assertJsonPath('qualified_leads', 1);
    }

    public function test_public_contact_extraction_is_provenanced_and_idempotent(): void
    {
        [$tenant] = $this->tenantAndUser('contact-tenant');
        $company = $this->company($tenant->id, 'Public Company');
        $websiteId = (string) Str::uuid();
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'url' => 'https://public.test', 'host' => 'public.test', 'created_at' => now(), 'updated_at' => now()]);
        $scanId = (string) Str::uuid();
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId,
            'status' => 'completed', 'max_depth' => 1, 'max_pages' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $key = "tenants/{$tenant->id}/crawls/{$scanId}/page.html";
        Storage::fake('local');
        Storage::disk('local')->put($key, '<a href="mailto:sales@public.test">Sales</a><a href="tel:+1-555-123-4567">Call us</a><a href="https://www.linkedin.com/company/public-company">Company LinkedIn</a>');
        DB::table('website_pages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId,
            'requested_url' => 'https://public.test/contact', 'final_url' => 'https://public.test/contact', 'object_key' => $key,
            'extracted_text' => 'Sales Call us', 'depth' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $extractor = app(PublicContactExtractor::class);
        self::assertSame(3, $extractor->extract($tenant->id, $scanId));
        self::assertSame(0, $extractor->extract($tenant->id, $scanId));
        $this->assertDatabaseCount('contacts', 3);
        $storedEmail = DB::table('contact_methods')->where('tenant_id', $tenant->id)->where('type', 'email')->first();
        self::assertNotSame('sales@public.test', $storedEmail->value);
        self::assertSame('sales@public.test', app(ContactMethodValue::class)->decrypt($storedEmail->value));
        self::assertSame(app(ContactMethodValue::class)->fingerprint('email', 'sales@public.test'), $storedEmail->value_hash);
        $this->assertDatabaseHas('contact_methods', ['tenant_id' => $tenant->id, 'type' => 'phone', 'source_url' => 'https://public.test/contact']);
        $this->assertDatabaseHas('contact_methods', ['tenant_id' => $tenant->id, 'type' => 'email', 'classification' => 'sales_email']);
        $this->assertDatabaseHas('contact_methods', ['tenant_id' => $tenant->id, 'type' => 'phone', 'classification' => 'business_phone']);
        $this->assertDatabaseHas('contact_methods', ['tenant_id' => $tenant->id, 'type' => 'social_profile', 'classification' => 'linkedin_company_profile']);
        self::assertSame(0, DB::table('contacts')->whereNotNull('name')->count());
    }

    public function test_contacts_api_returns_only_tenant_contacts_with_source_metadata(): void
    {
        [$tenant, $user] = $this->tenantAndUser('contacts-api-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = $this->company($tenant->id, 'Contacts Company');
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'name' => null, 'title' => null, 'source_url' => 'https://contacts.test/contact', 'observed_at' => now(),
            'extraction_method' => 'public_page_text', 'confidence' => 0.9, 'created_at' => now(), 'updated_at' => now()]);
        $contactValue = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'contact_id' => $contactId,
            'type' => 'email', 'value' => $contactValue->encrypt('hello@contacts.test'), 'value_hash' => $contactValue->fingerprint('email', 'hello@contacts.test'),
            'source_url' => 'https://contacts.test/contact', 'observed_at' => now(),
            'extraction_method' => 'public_page_text', 'confidence' => 0.9, 'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/contacts')
            ->assertOk()->assertJsonPath('data.0.company_name', 'Contacts Company')
            ->assertJsonPath('data.0.methods.0.value', 'hello@contacts.test')
            ->assertJsonPath('data.0.extraction_method', 'public_page_text');
    }

    public function test_screenshot_content_is_downloadable_only_inside_its_tenant(): void
    {
        [$tenant, $user] = $this->tenantAndUser('screenshot-tenant');
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = $this->company($tenant->id, 'Screenshot Company');
        $websiteId = (string) Str::uuid();
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'url' => 'https://screenshot.test', 'host' => 'screenshot.test', 'created_at' => now(), 'updated_at' => now()]);
        $scanId = (string) Str::uuid();
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId,
            'status' => 'completed', 'max_depth' => 1, 'max_pages' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $objectKey = "tenants/{$tenant->id}/crawls/{$scanId}/screenshots/test.png";
        Storage::fake('local');
        Storage::disk('local')->put($objectKey, 'test-image-bytes');
        $screenshotId = (string) Str::uuid();
        DB::table('website_screenshots')->insert(['id' => $screenshotId, 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId,
            'object_key' => $objectKey, 'viewport' => '1365x900', 'status' => 'stored', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)->get('/api/v1/website-screenshots/'.$screenshotId.'/content')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'screenshot-other', 'status' => 'active']);
        $user->tenants()->attach($otherTenant->id, ['role' => 'owner', 'status' => 'active']);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/website-screenshots/'.$screenshotId.'/content')->assertNotFound();
    }

    public function test_scoring_uses_persisted_icp_and_scan_evidence_and_replays_one_score_per_run(): void
    {
        [$tenant] = $this->tenantAndUser('scoring-tenant');
        $tenant->settings = ['scoring' => ['version' => 4, 'rules' => ScoringRuleEvaluator::DEFAULT_RULES,
            'icp' => ['industries' => ['Technology'], 'locations' => ['Toronto'], 'keywords' => ['cloud']]]];
        $tenant->save();
        $company = $this->company($tenant->id, 'Cloud Company', ['industry' => 'Technology', 'location' => 'Toronto', 'description' => 'Cloud services']);
        $websiteId = (string) Str::uuid();
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'url' => 'https://cloud.test', 'host' => 'cloud.test', 'created_at' => now(), 'updated_at' => now()]);
        $scanId = (string) Str::uuid();
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId,
            'status' => 'completed', 'max_depth' => 1, 'max_pages' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_pages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId,
            'requested_url' => 'https://cloud.test', 'final_url' => 'https://cloud.test', 'extracted_text' => 'WhatsApp contact', 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_issues')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId,
            'type' => 'outdated_website', 'severity' => 'high', 'summary' => 'Outdated website technology', 'confidence' => 0.9,
            'created_at' => now(), 'updated_at' => now()]);
        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'agent_key' => 'LeadScoringAgent', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);

        $agent = new LeadScoringAgent(new ScoringRuleEvaluator(), new LeadEvidenceBuilder());
        $context = new AgentContext($tenant->id, $runId, null, (string) Str::uuid());
        $first = $agent->execute($context, ['company_id' => $company->id]);
        $second = $agent->execute($context, ['company_id' => $company->id]);

        self::assertGreaterThan(0, $first->data['score']);
        self::assertSame($first->data, $second->data);
        self::assertSame(1, DB::table('lead_scores')->where('tenant_id', $tenant->id)->where('agent_run_id', $runId)->count());
        self::assertSame(4, $second->data['rule_version']);
    }

    private function tenantAndUser(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => 'Test User', 'email' => $slug.'@example.test', 'password' => 'test-password']);
        return [$tenant, $user];
    }

    private function company(string $tenantId, string $name, array $attributes = []): Company
    {
        return Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => $name,
            'normalized_domain' => strtolower(str_replace(' ', '-', $name)).'.test', 'status' => 'new', ...$attributes]);
    }
}
