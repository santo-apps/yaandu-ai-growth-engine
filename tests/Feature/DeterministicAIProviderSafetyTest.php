<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\AI\AIRequest;
use App\AI\JsonSchemaValidator;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\DeterministicAIProvider;
use App\Acceptance\LocalAcceptanceFixture;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class DeterministicAIProviderSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_router_routes_supported_acceptance_tasks_to_schema_valid_deterministic_outputs(): void
    {
        config(['ai.local_acceptance.enabled' => true]);
        $provider = new DeterministicAIProvider();
        $router = new AIModelRouter([$provider], [
            'content_generation' => ['provider' => 'deterministic', 'model' => 'local-acceptance-v1'],
            'sales_reasoning' => ['provider' => 'deterministic', 'model' => 'local-acceptance-v1'],
        ]);
        $evidenceId = (string) Str::uuid(); $knowledgeId = (string) Str::uuid(); $messageId = (string) Str::uuid();
        $marketing = $router->generate(new AIRequest('content_generation', 'Fixture only.', [
            'prospect' => ['company' => ['name' => 'Northstar Retail Systems Pvt Ltd'],
                'evidence' => [['id' => $evidenceId, 'observed_claim' => 'Older storefront layout.']]],
            'approved_yaandu_knowledge' => [['id' => $knowledgeId, 'content' => 'E-commerce modernization.']],
        ], ['type' => 'object', 'required' => ['subject','message','reasoning_summary','personalization_points','evidence_references','confidence','recommended_call_to_action'],
            'properties' => ['subject' => ['type' => 'string'], 'message' => ['type' => 'string'], 'evidence_references' => ['type' => 'array']]]), 'fixture-model');
        self::assertSame('deterministic', $marketing->provider);
        self::assertStringContainsString('Northstar Retail Systems Pvt Ltd', $marketing->data['subject']);
        self::assertStringNotContainsString('Ananya', $marketing->data['message']);
        self::assertStringNotContainsString('mobile', mb_strtolower($marketing->data['message']));
        self::assertSame([], $marketing->data['personalization_points']);
        self::assertContains($evidenceId, $marketing->data['evidence_references']);
        self::assertContains($knowledgeId, $marketing->data['evidence_references']);

        $issueId = (string) Str::uuid();
        $grounded = $provider->generate(new AIRequest('content_generation', 'Fixture only.', [
            'prospect' => ['company' => ['name' => 'Aster Medical'], 'evidence' => [['id' => $issueId, 'type' => 'website_issue', 'issue_type' => 'poor_mobile_ux']]],
        ], ['type' => 'object', 'required' => ['subject','message','reasoning_summary','personalization_points','evidence_references','confidence','recommended_call_to_action'],
            'properties' => ['subject' => ['type' => 'string'], 'message' => ['type' => 'string'], 'evidence_references' => ['type' => 'array']]]), 'fixture-model');
        self::assertSame(['A mobile experience review may be useful.'], $grounded->data['personalization_points']);
        self::assertSame([$issueId], $grounded->data['evidence_references']);
        self::assertStringNotContainsString('Northstar', $grounded->data['message']);

        $generic = $provider->generate(new AIRequest('content_generation', 'Fixture only.', [
            'prospect' => ['company' => ['name' => 'Unanalyzed Business'], 'evidence' => []],
        ], ['type' => 'object', 'required' => ['subject','message','reasoning_summary','personalization_points','evidence_references','confidence','recommended_call_to_action'],
            'properties' => ['subject' => ['type' => 'string'], 'message' => ['type' => 'string'], 'evidence_references' => ['type' => 'array']]]), 'fixture-model');
        self::assertStringNotContainsString('reviewed', mb_strtolower($generic->data['message']));
        self::assertSame([], $generic->data['evidence_references']);
        self::assertSame([], $generic->data['personalization_points']);

        $sales = $router->generate(new AIRequest('sales_reasoning', 'Fixture only.', [
            'conversation' => [['id' => $messageId, 'direction' => 'inbound', 'body' => 'Interested in modernization; can we meet?']],
        ], ['type' => 'object', 'required' => ['intent','confidence','reasoning_summary','draft_message','evidence_references'],
            'properties' => ['intent' => ['type' => 'string', 'enum' => ['interested','unsubscribe']], 'confidence' => ['type' => 'number'],
                'reasoning_summary' => ['type' => 'string'], 'draft_message' => ['type' => 'string'], 'evidence_references' => ['type' => 'array']]]), 'fixture-model');
        (new JsonSchemaValidator())->validate($sales->data, ['type' => 'object', 'required' => ['intent','confidence','reasoning_summary','draft_message','evidence_references'],
            'properties' => ['intent' => ['type' => 'string'], 'confidence' => ['type' => 'number'], 'reasoning_summary' => ['type' => 'string'],
                'draft_message' => ['type' => 'string'], 'evidence_references' => ['type' => 'array']]]);
        self::assertSame('interested', $sales->data['intent']);
        self::assertSame([$messageId], $sales->data['evidence_references']);
        self::assertArrayNotHasKey('approved', $sales->data);
        self::assertArrayNotHasKey('send', $sales->data);
        self::assertArrayNotHasKey('booked_meeting', $sales->data);
        self::assertArrayNotHasKey('ready_to_send', $sales->data);
    }

    public function test_provider_supports_schema_valid_grounded_website_and_proposal_recommendations_only(): void
    {
        config(['ai.local_acceptance.enabled' => true]);
        $provider = new DeterministicAIProvider();
        $serviceId = (string) Str::uuid(); $evidenceId = (string) Str::uuid(); $knowledgeId = (string) Str::uuid();
        $website = $provider->generate(new AIRequest('website_reasoning', 'Fixture only.', [[
            'url' => 'https://northstar-retail.fixture.test', 'text' => 'Retail ecommerce business with an older storefront layout. Mobile navigation is difficult.',
        ]], ['type' => 'object', 'required' => ['summary','issues','technologies','insights','contacts']]), 'fixture-model');
        self::assertSame('Local deterministic analysis based only on the supplied fictional page evidence.', $website->data['summary']);
        self::assertStringContainsString('older storefront layout', $website->data['issues'][0]['evidence']);
        (new JsonSchemaValidator())->validate($website->data, ['type' => 'object', 'required' => ['summary','issues','technologies','insights','contacts']]);

        $otherServiceId = (string) Str::uuid();
        $proposal = $provider->generate(new AIRequest('proposal_generation', 'Fixture only.', [
            'approved_tenant_services' => [
                ['id' => $otherServiceId, 'name' => 'AI-enabled customer engagement discovery', 'description' => 'Other approved scope.', 'standard_deliverables' => ['Engagement opportunity map']],
                ['id' => $serviceId, 'name' => 'E-commerce modernization discovery', 'description' => 'Scope description.', 'standard_deliverables' => ['Modernization opportunity map']],
            ],
            'approved_tenant_knowledge' => [['id' => $knowledgeId]],
            'prospect_data_untrusted' => ['company' => ['id' => (string) Str::uuid(), 'name' => 'Aster Medical Centre'], 'evidence' => [['id' => $evidenceId]],
                'requirements' => ['requested_services' => ['E-commerce modernization discovery']]],
        ], ['type' => 'object', 'required' => ['executive_summary','client_understanding','recommended_solution','scope']]), 'fixture-model');
        self::assertSame([$serviceId], $proposal->data['recommended_solution']);
        self::assertStringContainsString('Aster Medical Centre', $proposal->data['executive_summary']);
        self::assertStringNotContainsString('Northstar', json_encode($proposal->data, JSON_THROW_ON_ERROR));
        self::assertSame([$evidenceId], $proposal->data['evidence_references']);
        self::assertSame([$knowledgeId], $proposal->data['knowledge_references']);
        self::assertStringNotContainsString('₹', $proposal->data['commercial_narrative']);
        self::assertStringNotContainsString('%', $proposal->data['commercial_narrative']);
        self::assertArrayNotHasKey('approve', $proposal->data);
        self::assertArrayNotHasKey('ready_to_send', $proposal->data);
        try {
            $provider->generate(new AIRequest('website_visual_analysis', 'Fixture only.', [], []), 'fixture-model');
            self::fail('Unsupported AI task should fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not support task', $exception->getMessage());
        }
    }

    public function test_deterministic_reply_classifies_an_explicit_meeting_request(): void
    {
        config(['ai.local_acceptance.enabled' => true]);
        $provider = new DeterministicAIProvider();
        $messageId = (string) Str::uuid();
        $message = ['id' => $messageId, 'direction' => 'inbound', 'body' => 'We are interested. Can we book a meeting next week?'];

        $classification = $provider->generate(new AIRequest('sales_reasoning', 'Fixture only.', ['messages' => [$message]], [
            'type' => 'object', 'required' => ['intent','confidence','summary','reason','risk','evidence_references'],
            'properties' => ['summary' => ['type' => 'string']],
        ]), 'fixture-model');
        self::assertSame('meeting_request', $classification->data['intent']);
        self::assertSame('The prospect explicitly requested a meeting.', $classification->data['summary']);

        $recommendation = $provider->generate(new AIRequest('sales_reasoning', 'Fixture only.', ['conversation' => [$message]], [
            'type' => 'object', 'required' => ['intent','confidence','reasoning_summary','draft_message','evidence_references'],
            'properties' => ['intent' => ['type' => 'string']],
        ]), 'fixture-model');
        self::assertSame('meeting_request', $recommendation->data['intent']);
        self::assertStringContainsString('explicitly requested a meeting', $recommendation->data['reasoning_summary']);
        self::assertSame([$messageId], $recommendation->data['evidence_references']);
    }

    public function test_deterministic_provider_fails_closed_outside_local_or_testing_and_router_has_no_fake_fallback(): void
    {
        app()->detectEnvironment(static fn () => 'production');
        config(['ai.local_acceptance.enabled' => true]);
        try {
            new DeterministicAIProvider();
            self::fail('Deterministic AI must reject production environment.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('only when explicitly enabled', $exception->getMessage());
        }
        $router = new AIModelRouter([new AnthropicProvider()], ['content_generation' => ['provider' => 'deterministic', 'model' => 'local-acceptance-v1']]);
        try {
            $router->generate(new AIRequest('content_generation', 'Fixture only.', [], ['type' => 'object']));
            self::fail('Router must not fall back to a fake when it is not registered.');
        } catch (RuntimeException $exception) {
            self::assertSame('Configured AI provider [deterministic] is unavailable.', $exception->getMessage());
        }
    }

    public function test_acceptance_reset_and_simulator_fail_closed_outside_local_testing(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Ordinary tenant', 'slug' => 'ordinary-tenant']);
        $owner = User::create(['name' => 'Ordinary Owner', 'email' => 'ordinary-acceptance-safety@example.test', 'password' => Hash::make('test-only-password')]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        app()->detectEnvironment(static fn () => 'production');
        config(['ai.local_acceptance.enabled' => true]);

        self::assertSame(1, Artisan::call('product:acceptance-reset'));
        self::assertDatabaseHas('tenants', ['id' => $tenant->id, 'slug' => 'ordinary-tenant']);
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/local-acceptance/reply', ['intent' => 'interested'])->assertNotFound();
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/local-acceptance/status')->assertNotFound();
    }

    public function test_local_acceptance_controls_allow_only_the_explicit_sprint_seven_simulated_pilot(): void
    {
        config(['ai.local_acceptance.enabled' => true, 'pilot.allow_simulated_fixtures' => false]);
        $tenant = Tenant::create(['name' => 'Sprint 7 simulation', 'slug' => 'sprint-7-simulated-pilot', 'status' => 'active',
            'settings' => ['fixture_type' => 'yaandu_sprint_7_simulated_pilot', 'simulated' => true]]);
        $owner = User::create(['name' => 'Pilot Owner', 'email' => 'pilot-acceptance-tools@example.test', 'password' => Hash::make('test-only-password')]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/local-acceptance/status')
            ->assertOk()->assertJsonPath('mode', 'LOCAL ACCEPTANCE')->assertJsonPath('providers.outbound', 'FakeOutboundMessagingProvider');
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/local-acceptance/reply', ['intent' => 'interested'])
            ->assertStatus(409)->assertJsonPath('message', 'A fake outbound message for a local acceptance fixture must be marked sent before simulating a prospect reply.');

        $tenant->update(['settings' => ['fixture_type' => 'unrecognized', 'simulated' => true]]);
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/local-acceptance/status')->assertNotFound();
    }

    public function test_acceptance_reset_recreates_only_its_fixture_in_a_clean_pre_outreach_state(): void
    {
        config(['app.env' => 'testing', 'ai.local_acceptance.enabled' => true]);
        $ordinary = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Keep this tenant', 'slug' => 'unrelated-acceptance-tenant']);

        self::assertSame(0, Artisan::call('product:acceptance-reset'));
        $tenant = Tenant::where('slug', LocalAcceptanceFixture::TENANT_SLUG)->firstOrFail();
        $draft = \Illuminate\Support\Facades\DB::table('marketing_drafts')->where('tenant_id', $tenant->id)->firstOrFail();
        self::assertSame('draft', $draft->status);
        self::assertSame('deterministic', $draft->provider);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('sales_opportunities')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('meeting_bookings')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('proposals')->where('tenant_id', $tenant->id)->count());
        self::assertDatabaseHas('tenants', ['id' => $ordinary->id, 'slug' => 'unrelated-acceptance-tenant']);
        $firstTenantId = $tenant->id;

        self::assertSame(0, Artisan::call('product:acceptance-reset'));
        self::assertDatabaseMissing('tenants', ['id' => $firstTenantId]);
        self::assertDatabaseHas('tenants', ['id' => $ordinary->id, 'slug' => 'unrelated-acceptance-tenant']);
        self::assertSame(1, Tenant::where('slug', LocalAcceptanceFixture::TENANT_SLUG)->count());
    }
}
