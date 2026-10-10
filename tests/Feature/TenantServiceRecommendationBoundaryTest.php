<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;
use App\Agents\AgentContext;
use App\Agents\WebsiteIntelligenceAgent;
use App\Contacts\ContactMethodValue;
use App\Models\Tenant;
use App\Models\User;
use App\AI\ApprovedPromptRepository;
use App\WebsiteIntelligence\PublicContactExtractor;
use App\WebsiteIntelligence\TenantServiceCatalog;
use App\WebsiteIntelligence\YaanduServiceTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantServiceRecommendationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_only_current_tenant_active_approved_canonical_services(): void
    {
        [$tenant, $owner] = $this->workspace('service-boundary-a');
        [$other] = $this->workspace('service-boundary-b');
        $this->service($tenant, $owner, 'YND-WEB-PERF', true, true, 'website_modernization');
        $this->service($tenant, $owner, 'YND-WEB-REVAMP', true, true, 'website_modernization');
        $this->service($tenant, $owner, 'YND-CUSTOM', false, true, 'custom_software');
        $this->service($tenant, null, 'YND-AI', true, true, 'ai_agents');
        $this->service($tenant, $owner, 'seo', true, true); // A taxonomy-like SKU alone must not create capability eligibility.
        $this->service($tenant, $owner, 'YND-EXPIRED', true, false, 'conversion_rate_optimization');
        $this->service($other, $owner, 'YND-ECOM', true, true, 'ecommerce_development_migration');

        $services = app(TenantServiceCatalog::class)->recommendationServices($tenant->id);

        self::assertSame(['website_modernization'], $services->keys()->all());
        self::assertSame(['YND-WEB-PERF', 'YND-WEB-REVAMP'], $services->get('website_modernization')->pluck('sku')->all());
    }

    public function test_intelligence_readiness_is_blocked_without_recommendation_capable_tenant_services(): void
    {
        [$tenant, $owner] = $this->workspace('service-readiness');
        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/pilot/intelligence-readiness')->assertOk();

        $response->assertJsonPath('ready', false)
            ->assertJsonPath('service_catalog.exists', false)
            ->assertJsonPath('service_catalog.active_approved_count', 0)
            ->assertJsonPath('service_catalog.recommendation_capable_count', 0)
            ->assertJsonPath('service_catalog.prompt_service_key_compatible', false);
        $response->assertJsonPath('icp.configured', false)->assertJsonPath('icp.active', false);
        self::assertStringContainsString('active, approved tenant service', implode(' ', $response->json('reasons')));
    }

    public function test_unmapped_active_service_counts_as_approved_but_not_recommendation_capable(): void
    {
        [$tenant, $owner] = $this->workspace('unmapped-service-readiness');
        $this->service($tenant, $owner, 'custom_unmapped_sku', true, true);
        Sanctum::actingAs($owner);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/intelligence-readiness')->assertOk();

        $response->assertJsonPath('ready', false)
            ->assertJsonPath('service_catalog.exists', true)
            ->assertJsonPath('service_catalog.active_approved_count', 1)
            ->assertJsonPath('service_catalog.recommendation_capable_count', 0)
            ->assertJsonPath('service_catalog.prompt_service_key_compatible', false);
    }

    public function test_manager_can_map_a_commercial_sku_to_a_canonical_capability_without_changing_sku(): void
    {
        [$tenant, $owner] = $this->workspace('service-capability-mapping');
        $serviceId = $this->service($tenant, $owner, 'YND-ECOM-MOD', true, true);
        Sanctum::actingAs($owner);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/tenant-services/capabilities')->assertOk()
            ->assertJsonPath('ecommerce_development_migration', 'E-commerce development / migration');
        $this->withHeader('X-Tenant-ID', $tenant->id)->patchJson('/api/v1/tenant-services/'.$serviceId, [
            'canonical_service_key' => 'ecommerce_development_migration',
        ])->assertOk()->assertJsonPath('sku', 'YND-ECOM-MOD')->assertJsonPath('canonical_service_key', 'ecommerce_development_migration');
        $this->withHeader('X-Tenant-ID', $tenant->id)->patchJson('/api/v1/tenant-services/'.$serviceId, [
            'canonical_service_key' => 'YND-WEB-PERF',
        ])->assertUnprocessable();
        self::assertDatabaseHas('tenant_services', ['tenant_id' => $tenant->id, 'id' => $serviceId,
            'sku' => 'YND-ECOM-MOD', 'canonical_service_key' => 'ecommerce_development_migration']);
    }

    public function test_database_rejects_non_taxonomy_canonical_service_key(): void
    {
        [$tenant, $owner] = $this->workspace('invalid-canonical-key');
        foreach (YaanduServiceTaxonomy::keys() as $index => $key) $this->service($tenant, $owner, 'VALID-'.$index, true, true, $key);
        self::assertSame(count(YaanduServiceTaxonomy::keys()), DB::table('tenant_services')->where('tenant_id', $tenant->id)->whereNotNull('canonical_service_key')->count());
        try {
            $this->service($tenant, $owner, 'YND-INVALID', true, true, 'not_a_taxonomy_key');
            self::fail('The tenant_services canonical-key constraint should reject unsupported values.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertDatabaseMissing('tenant_services', ['tenant_id' => $tenant->id, 'sku' => 'YND-INVALID']);
        }
    }

    public function test_v2_prompt_receives_only_tenant_approved_services_and_returns_no_recommendations_when_catalog_is_empty(): void
    {
        Storage::fake('local');
        [$tenant, $owner] = $this->workspace('service-prompt-boundary');
        $companyId = (string) Str::uuid(); $websiteId = (string) Str::uuid(); $scanId = (string) Str::uuid();
        $pageId = (string) Str::uuid(); $runId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Example', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'url' => 'https://service-prompt.test/', 'host' => 'service-prompt.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId, 'status' => 'completed', 'max_depth' => 1, 'max_pages' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $excerpt = 'Example provides industrial packaging services.';
        DB::table('website_pages')->insert(['id' => $pageId, 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId, 'requested_url' => 'https://service-prompt.test/',
            'final_url' => 'https://service-prompt.test/', 'title' => 'Example', 'http_status' => 200, 'extracted_text' => $excerpt, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prompt_templates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'version' => 2,
            'system_instruction' => 'Use only tenant-approved service offerings in the supplied catalog.', 'template' => 'Return evidence-grounded structured analysis.',
            'schema_version' => 'website-intelligence-pilot-v2', 'active' => true, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'task_key' => 'website_reasoning', 'provider' => 'anthropic',
            'model' => 'test-model', 'enabled' => true, 'parameters' => '{}', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $modelData = ['business_identity' => ['name' => 'Example', 'description' => 'Industrial packaging services', 'evidence_id' => $pageId, 'excerpt' => $excerpt],
            'observations' => [], 'technical_findings' => [], 'opportunities' => [], 'service_recommendations' => [[
                'service_key' => 'website_modernization', 'recommendation_strength' => 'tentative', 'evidence_ids' => [], 'rationale' => 'Unmapped taxonomy-only suggestion',
                'confidence' => 0.7, 'missing_information' => ['No active tenant service exists'], 'discovery_question' => 'Would this service be relevant?',
                'recommended_next_action' => 'Validate with an authorized seller.', 'speculative' => true,
            ]], 'unknowns' => [],
            'evidence' => [['evidence_id' => $pageId, 'source_url' => 'https://service-prompt.test/', 'excerpt' => $excerpt]], 'confidence' => 0.9, 'next_actions' => []];
        $provider = new class($modelData) implements AIProviderInterface {
            public string $systemInstruction = '';
            public function __construct(private array $data) {}
            public function providerKey(): string { return 'anthropic'; }
            public function capabilities(): array { return ['structured_json']; }
            public function generate(AIRequest $request, string $model): AIResponse { $this->systemInstruction = $request->systemInstruction; return new AIResponse($this->data, 'anthropic', $model); }
        };
        $agent = new WebsiteIntelligenceAgent(new AIModelRouter([$provider], config('ai.tasks')), new PublicContactExtractor(app(ContactMethodValue::class)), app(ApprovedPromptRepository::class));

        $result = $agent->execute(new AgentContext($tenant->id, $runId, (string) $owner->id, (string) Str::uuid()), ['website_scan_id' => $scanId]);

        self::assertSame([], $result->data['service_recommendations']);
        self::assertStringContainsString('Tenant-approved active service offerings', $provider->systemInstruction);
        self::assertStringContainsString('[]', $provider->systemInstruction);
        self::assertStringNotContainsString('website_modernization', $provider->systemInstruction);
        self::assertStringNotContainsString('Custom software', $provider->systemInstruction);
    }

    public function test_recommendations_require_verified_opportunity_service_and_next_action_evidence_end_to_end(): void
    {
        Storage::fake('local');
        [$tenant, $owner] = $this->workspace('recommendation-evidence-chain');
        $primaryService = $this->service($tenant, $owner, 'YND-WEB-PERF', true, true, 'website_modernization');
        $secondaryService = $this->service($tenant, $owner, 'YND-WEB-REVAMP', true, true, 'website_modernization');
        $companyId = (string) Str::uuid(); $websiteId = (string) Str::uuid(); $scanId = (string) Str::uuid();
        $pageId = (string) Str::uuid(); $runId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Example Company', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'url' => 'https://recommendation.test/', 'host' => 'recommendation.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId, 'status' => 'completed', 'max_depth' => 1, 'max_pages' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $excerpt = 'Contact our team by calling our office.';
        DB::table('website_pages')->insert(['id' => $pageId, 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId, 'requested_url' => 'https://recommendation.test/',
            'final_url' => 'https://recommendation.test/', 'title' => 'Example', 'http_status' => 200, 'extracted_text' => $excerpt, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prompt_templates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'version' => 2,
            'system_instruction' => 'Cite evidence for every recommendation.', 'template' => 'Return evidence-grounded structured analysis.',
            'schema_version' => 'website-intelligence-pilot-v2', 'active' => true, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'task_key' => 'website_reasoning', 'provider' => 'anthropic',
            'model' => 'test-model', 'enabled' => true, 'parameters' => '{}', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $data = ['business_identity' => ['name' => 'Example', 'description' => 'Example services', 'evidence_id' => $pageId, 'excerpt' => $excerpt],
            'observations' => [['statement' => 'The website may need modernization.', 'kind' => 'opportunity', 'evidence_id' => $pageId, 'excerpt' => $excerpt, 'confidence' => 0.9]],
            'technical_findings' => [], 'opportunities' => [['statement' => 'The enquiry path relies on a phone call.', 'kind' => 'opportunity',
                'evidence_id' => $pageId, 'excerpt' => $excerpt, 'confidence' => 0.9]],
            'service_recommendations' => [
                ['service_key' => 'website_modernization', 'recommendation_strength' => 'moderate', 'evidence_ids' => [$pageId],
                    'rationale' => 'Improve the enquiry path based on the observed phone-only contact method.', 'confidence' => 0.85,
                    'missing_information' => [], 'discovery_question' => 'Which enquiries should be prioritized?',
                    'recommended_next_action' => 'Review the enquiry journey with the owner.', 'speculative' => false],
                ['service_key' => 'website_modernization', 'recommendation_strength' => 'tentative', 'evidence_ids' => [$pageId],
                    'rationale' => 'Possible AI automation opportunity.', 'confidence' => 0.6, 'missing_information' => ['Workflows are unknown'],
                    'discovery_question' => 'Are there repetitive workflows?', 'recommended_next_action' => 'Review the enquiry journey with the owner.', 'speculative' => true],
            ], 'unknowns' => [], 'evidence' => [['evidence_id' => $pageId, 'source_url' => 'https://recommendation.test/', 'excerpt' => $excerpt]],
            'confidence' => 0.9, 'next_actions' => [['action' => 'Review the enquiry journey with the owner.', 'evidence_ids' => [$pageId], 'confidence' => 0.8]]];
        $provider = new class($data) implements AIProviderInterface {
            public array $schema = [];
            public string $systemInstruction = '';
            public function __construct(private array $data) {}
            public function providerKey(): string { return 'anthropic'; }
            public function capabilities(): array { return ['structured_json']; }
            public function generate(AIRequest $request, string $model): AIResponse { $this->schema = $request->outputSchema; $this->systemInstruction = $request->systemInstruction; return new AIResponse($this->data, 'anthropic', $model); }
        };
        $agent = new WebsiteIntelligenceAgent(new AIModelRouter([$provider], config('ai.tasks')), new PublicContactExtractor(app(ContactMethodValue::class)), app(ApprovedPromptRepository::class));
        $result = $agent->execute(new AgentContext($tenant->id, $runId, (string) $owner->id, (string) Str::uuid()), ['website_scan_id' => $scanId]);

        self::assertCount(1, $result->data['service_recommendations']);
        self::assertSame([$pageId], $result->data['service_recommendations'][0]['evidence_ids']);
        self::assertSame('website_modernization', $result->data['service_recommendations'][0]['service_key']);
        self::assertSame([$primaryService, $secondaryService], array_column($result->data['service_recommendations'][0]['tenant_services'], 'id'));
        self::assertArrayNotHasKey('tenant_service_id', $result->data['service_recommendations'][0], 'A shared capability must not be silently assigned to one SKU.');
        self::assertSame(['website_modernization'], $provider->schema['properties']['service_recommendations']['items']['properties']['service_key']['enum']);
        $offeringsSection = explode("Tenant-approved active service offerings (the only offerings that may be recommended; map every recommendation to an exact service_key; if empty, return no service recommendations):\n", $provider->systemInstruction)[1] ?? '[]';
        $offeringsJson = explode("\n\nApproved analysis template", $offeringsSection)[0];
        $offerings = json_decode($offeringsJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['website_modernization', 'website_modernization'], array_column($offerings, 'service_key'));
        self::assertSame(['YND-WEB-PERF', 'YND-WEB-REVAMP'], array_column($offerings, 'sku'));
        $persisted = DB::table('website_intelligence_results')->where('tenant_id', $tenant->id)->where('agent_run_id', $runId)->first();
        $structured = json_decode($persisted->structured_output, true);
        self::assertSame([$pageId], $structured['service_recommendations'][0]['evidence_ids']);
        self::assertArrayNotHasKey('evidence_ids', $structured['service_recommendations'][1] ?? []);
        self::assertDatabaseHas('lead_insights', ['tenant_id' => $tenant->id, 'company_id' => $companyId, 'agent_run_id' => $runId, 'kind' => 'service_recommendation']);
        self::assertDatabaseHas('lead_evidence', ['tenant_id' => $tenant->id, 'source_url' => 'https://recommendation.test/', 'excerpt' => $excerpt]);
        self::assertDatabaseMissing('lead_insights', ['tenant_id' => $tenant->id, 'company_id' => $companyId, 'agent_run_id' => $runId,
            'kind' => 'opportunity', 'statement' => 'The website may need modernization.']);
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('workflow_approvals')->where('tenant_id', $tenant->id)->where('action', 'SEND_OUTREACH')->count());
        self::assertSame('positive', app(\App\LeadScoring\LeadEvidenceBuilder::class)->build($tenant->id, $companyId)['strong_business_fit']['status'],
            'Only the separately validated opportunity may contribute to business-fit scoring.');

        Sanctum::actingAs($owner);
        $apiResult = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/companies/'.$companyId.'/intelligence')->assertOk()->json('intelligence_results.0.structured_output');
        self::assertSame([$pageId], $apiResult['service_recommendations'][0]['evidence_ids']);
    }

    private function workspace(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $owner = User::create(['name' => $slug, 'email' => $slug.'@example.test', 'password' => bcrypt(Str::random(32))]);
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return [$tenant, $owner];
    }

    private function service(Tenant $tenant, ?User $approvedBy, string $sku, bool $active, bool $effective, ?string $canonicalKey = null): string
    {
        $id = (string) Str::uuid();
        DB::table('tenant_services')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'sku' => $sku, 'canonical_service_key' => $canonicalKey,
            'name' => ucwords(str_replace('_', ' ', $sku)), 'description' => 'test', 'unit_price' => '1.00', 'currency' => 'INR',
            'active' => $active, 'commercial_model' => 'FIXED_PRICE', 'unit' => 'project', 'approved_by' => $approvedBy?->id,
            'effective_from' => $effective ? now()->subDay() : now()->addDay(), 'effective_until' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
