<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Models\Company;
use App\Models\Tenant;
use App\Orchestration\AcquisitionWorkflowCoordinator;
use App\Orchestration\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcquisitionWorkflowCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_deterministic_action_dispatch_is_tenant_scoped_and_replay_safe(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Coordinator', 'slug' => 'coordinator-test']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Prospect', 'normalized_domain' => 'prospect.test', 'status' => 'new']);
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id]);

        $resolved = app(AcquisitionWorkflowCoordinator::class)->resolve($tenant->id, $workflow->id, 'website_analysis_completed');
        self::assertSame('RUN_LEAD_SCORING', $resolved['action']);
        self::assertSame('AUTO_ALLOWED', $resolved['decision']);

        $first = app(AcquisitionWorkflowCoordinator::class)->consume($tenant->id, $workflow->id, 'website_analysis_completed');
        $replay = app(AcquisitionWorkflowCoordinator::class)->consume($tenant->id, $workflow->id, 'website_analysis_completed');
        self::assertSame($first['agent_run_id'], $replay['agent_run_id']);
        self::assertSame(1, DB::table('agent_runs')->where('tenant_id', $tenant->id)->where('idempotency_key', 'coordinator:'.$workflow->id.':LeadScoringAgent')->count());
        Queue::assertPushed(RunAgentJob::class, fn (RunAgentJob $job) => $job->tenantId === $tenant->id && $job->agentName === 'LeadScoringAgent');
    }

    public function test_coordinator_denies_dispatch_for_terminal_workflow(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Stopped', 'slug' => 'coordinator-stopped']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Stopped prospect', 'normalized_domain' => 'stopped.test', 'status' => 'new']);
        $workflow = app(WorkflowService::class)->create($tenant->id, ['company_id' => $company->id]);
        DB::table('acquisition_workflows')->where('tenant_id', $tenant->id)->where('id', $workflow->id)->update(['status' => 'CANCELLED']);

        $decision = app(AcquisitionWorkflowCoordinator::class)->consume($tenant->id, $workflow->id, 'website_analysis_completed');
        self::assertSame('DENIED', $decision['decision']);
        self::assertSame('workflow_not_runnable', $decision['reason']);
        self::assertDatabaseCount('agent_runs', 0);
        Queue::assertNothingPushed();
    }
}
