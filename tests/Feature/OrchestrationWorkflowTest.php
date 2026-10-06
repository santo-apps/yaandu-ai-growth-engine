<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;
use App\Agents\AgentContext;
use App\Agents\AgentInterface;
use App\Agents\AgentOrchestrator;
use App\Agents\AgentResult;
use App\Jobs\RunAgentJob;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Orchestration\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrchestrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_lifecycle_is_tenant_scoped_and_controlled(): void
    {
        [$tenant, $owner, $company] = $this->workspace('orch-lifecycle');
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $workflow = $this->withHeaders($headers)->postJson('/api/v1/automation/workflows', ['company_id' => $company->id])->assertOk()->json('data');
        self::assertSame('B2B_ACQUISITION', $workflow['workflow_type']);
        self::assertSame('RUNNING', $workflow['status']);
        $id = $workflow['id'];
        $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$id.'/pause')->assertOk()->assertJsonPath('data.status', 'PAUSED');
        $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$id.'/resume')->assertOk()->assertJsonPath('data.status', 'RUNNING');
        $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'workflow.cancel']);

        [$otherTenant, , $otherCompany] = $this->workspace('orch-other');
        $other = app(WorkflowService::class)->create($otherTenant->id, ['company_id' => $otherCompany->id]);
        $this->withHeaders($headers)->getJson('/api/v1/automation/workflows/'.$other->id)->assertNotFound();
        $this->withHeaders($headers)->postJson('/api/v1/automation/workflows', ['company_id' => $otherCompany->id])->assertUnprocessable();
    }

    public function test_approval_payload_is_immutable_idempotent_and_requires_manager(): void
    {
        [$tenant, $owner, $company] = $this->workspace('orch-approval');
        $conversation = $this->conversation($tenant->id, $company->id);
        $this->enableScheduling($tenant->id);
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id, 'conversation_id' => $conversation->id]);
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'approve-enroll-1'];
        $body = ['action' => 'REQUEST_MEETING', 'reason' => 'The prospect requested a discovery meeting.', 'payload' => ['target_type' => 'conversation', 'target_id' => $conversation->id, 'timezone' => 'UTC']];
        $approval = $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$workflow->id.'/approvals', $body)->assertOk()->json('data');
        $duplicate = $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$workflow->id.'/approvals', $body)->assertOk()->json('data');
        self::assertSame($approval['id'], $duplicate['id']);
        $this->withHeaders($headers)->getJson('/api/v1/automation/summary')->assertOk()->assertJsonPath('waiting_approval', 1)->assertJsonPath('approvals_pending', 1);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/automation/approvals')->assertOk()->assertJsonPath('data.data.0.action', 'REQUEST_MEETING')
            ->assertJsonPath('data.data.0.content_summary', 'Meeting request for the tenant-scoped conversation.')
            ->assertJsonMissingPath('data.data.0.payload_snapshot');
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->postJson('/api/v1/automation/approvals/'.$approval['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'EXECUTED');
        $this->assertDatabaseHas('scheduling_requests', ['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'status' => 'REQUESTED']);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->postJson('/api/v1/automation/approvals/'.$approval['id'].'/approve')->assertUnprocessable();

        $member = User::create(['name' => 'Member', 'email' => 'orch-member@example.test', 'password' => 'password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $other = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id, 'conversation_id' => $conversation->id]);
        $pending = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'member-approval'])->postJson('/api/v1/automation/workflows/'.$other->id.'/approvals', $body)->json('data');
        $this->withHeaders(['X-Tenant-ID' => $tenant->id])->postJson('/api/v1/automation/approvals/'.$pending['id'].'/approve')->assertForbidden();
    }

    public function test_approval_payload_mutation_cross_tenant_access_and_expiry_fail_closed(): void
    {
        [$tenant, $owner, $company] = $this->workspace('orch-approval-boundary');
        [$otherTenant, , $otherCompany] = $this->workspace('orch-approval-other');
        $conversation = $this->conversation($tenant->id, $company->id);
        $this->enableScheduling($tenant->id);
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id, 'conversation_id' => $conversation->id]);
        $foreign = app(WorkflowService::class)->create($otherTenant->id, ['company_id' => $otherCompany->id]);
        Sanctum::actingAs($owner); $headers = ['X-Tenant-ID' => $tenant->id];
        $data = ['action' => 'REQUEST_MEETING', 'reason' => 'Approved meeting request.', 'payload' => ['target_type' => 'conversation', 'target_id' => $conversation->id, 'timezone' => 'UTC']];
        $first = $this->withHeaders([...$headers, 'Idempotency-Key' => 'payload-mutation'])->postJson('/api/v1/automation/workflows/'.$workflow->id.'/approvals', $data)->json('data');
        $this->withHeaders($headers)->postJson('/api/v1/automation/approvals/'.$first['id'].'/approve')->assertOk();
        $workflowTwo = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id, 'conversation_id' => $conversation->id]);
        $second = $this->withHeaders([...$headers, 'Idempotency-Key' => 'payload-expiry'])->postJson('/api/v1/automation/workflows/'.$workflowTwo->id.'/approvals', $data)->json('data');
        DB::table('workflow_approvals')->where('id', $second['id'])->update(['payload_snapshot' => json_encode(['target_type' => 'campaign', 'target_id' => 'mutated'])]);
        $this->withHeaders($headers)->postJson('/api/v1/automation/approvals/'.$second['id'].'/approve')->assertUnprocessable();
        $workflowThree = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id, 'conversation_id' => $conversation->id]);
        $third = $this->withHeaders([...$headers, 'Idempotency-Key' => 'approval-expired'])->postJson('/api/v1/automation/workflows/'.$workflowThree->id.'/approvals', $data)->json('data');
        DB::table('workflow_approvals')->where('id', $third['id'])->update(['expires_at' => now()->subMinute()]);
        $this->withHeaders($headers)->postJson('/api/v1/automation/approvals/'.$third['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'EXPIRED');
        $this->withHeaders($headers)->postJson('/api/v1/automation/workflows/'.$foreign->id.'/approvals', $data)->assertNotFound();
        $this->assertDatabaseHas('workflow_approvals', ['id' => $third['id'], 'status' => 'EXPIRED']);
    }

    public function test_tenant_policy_cannot_weaken_system_action_and_admin_is_required(): void
    {
        [$tenant, $owner] = $this->workspace('orch-policy');
        Sanctum::actingAs($owner); $headers = ['X-Tenant-ID' => $tenant->id];
        $this->withHeaders($headers)->getJson('/api/v1/automation/policies')->assertOk()->assertJsonPath('actions.0.action', 'RUN_WEBSITE_ANALYSIS');
        $this->withHeaders($headers)->putJson('/api/v1/automation/policies', ['autonomy_mode' => 'CONTROLLED', 'actions' => [['action' => 'SEND_PROPOSAL', 'policy' => 'AUTO_ALLOWED']]])->assertUnprocessable();
        $this->withHeaders($headers)->putJson('/api/v1/automation/policies', ['autonomy_mode' => 'CONTROLLED', 'daily_ai_call_limit' => 40,
            'actions' => [['action' => 'RUN_WEBSITE_ANALYSIS', 'policy' => 'DENIED']]])->assertOk()->assertJsonPath('autonomy_mode', 'CONTROLLED');
        $member = User::create(['name' => 'Policy member', 'email' => 'policy-member@example.test', 'password' => 'password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']); Sanctum::actingAs($member);
        $this->withHeaders($headers)->putJson('/api/v1/automation/policies', ['autonomy_mode' => 'MANUAL'])->assertForbidden();
    }

    public function test_duplicate_events_are_idempotent_and_loop_limit_pauses_workflow(): void
    {
        [$tenant, , $company] = $this->workspace('orch-loop');
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id]);
        $service = app(WorkflowService::class);
        $first = $service->append($tenant->id, $workflow->id, 'lead_scored', 'agent', ['company_id' => $company->id], 'lead-score-once');
        $same = $service->append($tenant->id, $workflow->id, 'lead_scored', 'agent', ['company_id' => $company->id], 'lead-score-once');
        self::assertSame($first->id, $same->id);
        for ($i = 0; $i < 99; $i++) $service->append($tenant->id, $workflow->id, 'agent_run_completed', 'agent', [], 'loop-'.$i);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'id' => $workflow->id, 'status' => 'PAUSED']);
        $this->assertDatabaseHas('workflow_events', ['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'event' => 'loop_limit_human_review']);
    }

    public function test_existing_agent_execution_is_linked_into_deterministic_workflow_timeline(): void
    {
        [$tenant, , $company] = $this->workspace('orch-agent-link');
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id]);
        app(AgentOrchestrator::class)->run('LeadScoringAgent', $tenant->id, ['company_id' => $company->id]);
        $this->assertDatabaseHas('workflow_events', ['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'event' => 'lead_scored']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'id' => $workflow->id, 'current_stage' => 'LEAD_SCORING']);
        self::assertSame(1, DB::table('workflow_events')->where('tenant_id', $tenant->id)->where('workflow_id', $workflow->id)->where('event', 'lead_scored')->count());
    }

    public function test_ai_usage_is_tenant_scoped_and_call_budget_fails_closed(): void
    {
        [$tenant] = $this->workspace('orch-ai-budget');
        DB::table('tenant_automation_settings')->insert(['tenant_id' => $tenant->id, 'autonomy_mode' => 'ASSISTED', 'daily_ai_call_limit' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $provider = new OrchestrationFakeProvider();
        $router = new AIModelRouter([$provider], ['test_task' => ['provider' => 'orchestration-fake', 'model' => 'fixed-model']]);
        $request = new AIRequest('test_task', 'fixed instruction', [], ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']], tenantId: $tenant->id);
        $router->generate($request);
        self::assertSame(1, DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->count());
        $this->expectException(\RuntimeException::class);
        $router->generate($request);
    }

    public function test_only_retryable_agent_failure_can_be_requeued_with_verified_encrypted_input(): void
    {
        [$tenant, , $company] = $this->workspace('orch-retry');
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id]);
        $input = ['company_id' => $company->id, 'private_marker' => 'do-not-store-plaintext'];
        try { (new AgentOrchestrator([new RetryableFailureAgent()]))->run('RetryableFailureAgent', $tenant->id, $input); } catch (RetryProviderUnavailableException) {}
        $run = DB::table('agent_runs')->where('tenant_id', $tenant->id)->first();
        self::assertNotNull($run->input_ciphertext);
        self::assertStringNotContainsString('do-not-store-plaintext', $run->input_ciphertext);
        $this->assertDatabaseHas('workflow_events', ['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'event' => 'agent_run_retryable_failure']);
        Queue::fake();
        $retried = app(WorkflowService::class)->control($tenant->id, $workflow->id, 'retry');
        self::assertSame('RUNNING', $retried->status);
        self::assertSame(1, $retried->retry_count);
        Queue::assertPushed(RunAgentJob::class, fn (RunAgentJob $job): bool => $job->runId === $run->id && $job->input === $input);
    }

    private function workspace(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $owner = User::create(['name' => 'Owner', 'email' => $slug.'@example.test', 'password' => 'password']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Prospect '.$slug, 'normalized_domain' => $slug.'.example.test', 'status' => 'new']);
        return [$tenant, $owner, $company];
    }

    private function conversation(string $tenantId, string $companyId): Conversation
    {
        return Conversation::create(['tenant_id' => $tenantId, 'company_id' => $companyId, 'channel' => 'email', 'status' => 'ai_active']);
    }

    private function enableScheduling(string $tenantId): void
    {
        DB::table('tenant_scheduling_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'provider' => 'fake', 'enabled' => true,
            'default_timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
    }
}

final class OrchestrationFakeProvider implements AIProviderInterface
{
    public function providerKey(): string { return 'orchestration-fake'; }
    public function capabilities(): array { return ['structured_json']; }
    public function generate(AIRequest $request, string $model): AIResponse { return new AIResponse(['ok' => true], $this->providerKey(), $model, 45, 7); }
}

final class RetryableFailureAgent implements AgentInterface
{
    public function name(): string { return 'RetryableFailureAgent'; }
    public function description(): string { return 'Failure fixture'; }
    public function inputSchema(): array { return ['type' => 'object', 'properties' => ['company_id' => ['type' => 'string'], 'private_marker' => ['type' => 'string']], 'required' => ['company_id', 'private_marker'], 'additionalProperties' => false]; }
    public function outputSchema(): array { return ['type' => 'object', 'properties' => [], 'additionalProperties' => false]; }
    public function tools(): array { return []; }
    public function execute(AgentContext $context, array $input): AgentResult { throw new RetryProviderUnavailableException(); }
}

final class RetryProviderUnavailableException extends \RuntimeException {}
