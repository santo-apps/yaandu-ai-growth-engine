<?php

namespace App\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkflowService
{
    public const MAX_STEPS = 100;
    public const MAX_AGENT_RUNS_PER_STAGE = 5;
    public const MAX_RETRIES = 3;

    public function __construct(private readonly WorkflowTransitionMap $transitions) {}

    public function create(string $tenantId, array $input, ?string $actorId = null): object
    {
        foreach (['company_id' => 'companies', 'contact_id' => 'contacts', 'campaign_id' => 'campaigns', 'enrollment_id' => 'campaign_recipients', 'conversation_id' => 'conversations', 'opportunity_id' => 'sales_opportunities'] as $field => $table) {
            $record = ! empty($input[$field]) ? DB::table($table)->where('tenant_id', $tenantId)->where('id', $input[$field]) : null;
            if ($field === 'company_id') $record?->where('status', '!=', 'discovery_candidate');
            if ($record && ! $record->exists()) {
                throw ValidationException::withMessages([$field => 'The selected record is not available in this tenant.']);
            }
        }
        $id = (string) Str::uuid();
        $correlation = (string) Str::uuid();
        return DB::transaction(function () use ($tenantId, $input, $actorId, $id, $correlation): object {
            $settings = DB::table('tenant_automation_settings')->where('tenant_id', $tenantId)->first();
            $mode = AutonomyMode::tryFrom($settings->autonomy_mode ?? 'ASSISTED') ?? AutonomyMode::Assisted;
            DB::table('acquisition_workflows')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'workflow_type' => 'B2B_ACQUISITION',
                'company_id' => $input['company_id'] ?? null, 'contact_id' => $input['contact_id'] ?? null,
                'campaign_id' => $input['campaign_id'] ?? null, 'enrollment_id' => $input['enrollment_id'] ?? null,
                'conversation_id' => $input['conversation_id'] ?? null, 'opportunity_id' => $input['opportunity_id'] ?? null,
                'current_stage' => WorkflowStage::tryFrom($input['initial_stage'] ?? '')?->value ?? WorkflowStage::Discovery->value,
                'status' => WorkflowStatus::Running->value, 'autonomy_policy' => $mode->value, 'correlation_id' => $correlation,
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->append($tenantId, $id, 'workflow_started', 'api', ['workflow_type' => 'B2B_ACQUISITION'], 'workflow:started:'.$id, $actorId);
            return DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $id)->first();
        });
    }

    public function assertAgentExecutionAllowed(string $tenantId, array $input): void
    {
        $tenantStatus = DB::table('tenants')->where('id', $tenantId)->value('status');
        if ($tenantStatus !== null && $tenantStatus !== 'active') {
            $this->stopTenant($tenantId);
            throw new \RuntimeException('AI execution is unavailable for this tenant.');
        }
        if (! empty($input['campaign_id']) && DB::table('campaigns')->where('tenant_id', $tenantId)
            ->where('id', $input['campaign_id'])->where('status', 'cancelled')->exists()) {
            throw new \RuntimeException('Agent execution is stopped because the campaign was cancelled.');
        }
        if (! empty($input['website_scan_id'])) {
            $companyId = DB::table('website_scans as s')->join('company_websites as w', function ($join): void { $join->on('w.id','=','s.company_website_id')->on('w.tenant_id','=','s.tenant_id'); })
                ->where('s.tenant_id', $tenantId)->where('s.id', $input['website_scan_id'])->value('w.company_id');
            if ($companyId) $input['company_id'] = $companyId;
        }
        if (! empty($input['proposal_id'])) {
            $companyId = DB::table('proposals as p')->join('sales_opportunities as o', function ($join): void { $join->on('o.id','=','p.sales_opportunity_id')->on('o.tenant_id','=','p.tenant_id'); })
                ->where('p.tenant_id', $tenantId)->where('p.id', $input['proposal_id'])->value('o.company_id');
            if ($companyId) $input['company_id'] = $companyId;
        }
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId);
        if (! empty($input['workflow_id'])) $query->where('id', $input['workflow_id']);
        elseif (! empty($input['campaign_id'])) $query->where('campaign_id', $input['campaign_id']);
        elseif (! empty($input['enrollment_id'])) $query->where('enrollment_id', $input['enrollment_id']);
        elseif (! empty($input['opportunity_id'])) $query->where('opportunity_id', $input['opportunity_id']);
        elseif (! empty($input['conversation_id'])) $query->where('conversation_id', $input['conversation_id']);
        elseif (! empty($input['company_id'])) $query->where('company_id', $input['company_id'])
            ->whereNotIn('status', [WorkflowStatus::Cancelled->value, WorkflowStatus::Completed->value]);
        else $query = null;
        $workflow = $query?->orderByDesc('updated_at')->first();
        if ($workflow && (in_array($workflow->status, [WorkflowStatus::Paused->value, WorkflowStatus::Cancelled->value, WorkflowStatus::Completed->value], true)
            || $workflow->current_stage === WorkflowStage::HumanHandoff->value)) {
            throw new \RuntimeException('Agent execution is stopped by workflow ownership or policy.');
        }
        if ($workflow?->campaign_id && DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $workflow->campaign_id)->where('status', 'cancelled')->exists()) {
            throw new \RuntimeException('Agent execution is stopped because the campaign was cancelled.');
        }
        if ($workflow?->enrollment_id && DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $workflow->enrollment_id)
            ->whereIn('status', ['stopped','completed','suppressed','handed_off'])->exists()) {
            throw new \RuntimeException('Agent execution is stopped for this campaign enrollment.');
        }
        if ($workflow?->opportunity_id && DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $workflow->opportunity_id)
            ->whereIn('stage', ['CLOSED','NOT_QUALIFIED'])->exists()) {
            throw new \RuntimeException('Agent execution is stopped for a closed opportunity.');
        }
    }

    public function recordConversationEvent(string $tenantId, string $conversationId, string $event, string $idempotencyKey, ?string $actorId = null): void
    {
        $conversation = DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $conversationId)->first(['company_id','sales_opportunity_id']);
        if (! $conversation) return;
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId);
        if (DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('conversation_id', $conversationId)->exists()) {
            $query->where('conversation_id', $conversationId);
        } elseif ($conversation->sales_opportunity_id && DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('opportunity_id', $conversation->sales_opportunity_id)->exists()) {
            $query->where('opportunity_id', $conversation->sales_opportunity_id);
        } elseif ($conversation->company_id) {
            $query->where('company_id', $conversation->company_id);
        } else return;
        $workflow = $query->orderByDesc('updated_at')->first();
        if ($workflow && ! in_array($workflow->status, [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value], true)) {
            if (! $workflow->conversation_id) {
                DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)
                    ->whereNull('conversation_id')->update(['conversation_id' => $conversationId, 'updated_at' => now()]);
            }
            $this->append($tenantId, $workflow->id, $event, 'domain', ['conversation_id' => $conversationId], $idempotencyKey, $actorId);
        }
    }

    public function recordCampaignCancellation(string $tenantId, string $campaignId): void
    {
        $enrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->pluck('id');
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where(function ($q) use ($campaignId, $enrollments): void {
            $q->where('campaign_id', $campaignId);
            if ($enrollments->isNotEmpty()) $q->orWhereIn('enrollment_id', $enrollments);
        })->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value]);
        foreach ($query->get(['id']) as $workflow) {
            $this->append($tenantId, $workflow->id, 'workflow_cancelled', 'domain', ['campaign_id' => $campaignId], 'campaign-cancelled:'.$campaignId.':'.$workflow->id);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        }
    }

    public function recordCampaignEvent(string $tenantId, string $campaignId, string $event, string $idempotencyKey, array $metadata = []): void
    {
        $enrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->pluck('id');
        $workflows = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])
            ->where(function ($query) use ($campaignId, $enrollments): void {
                $query->where('campaign_id', $campaignId);
                if ($enrollments->isNotEmpty()) $query->orWhereIn('enrollment_id', $enrollments);
            })->get(['id']);
        foreach ($workflows as $workflow) $this->append($tenantId, $workflow->id, $event, 'domain', ['campaign_id' => $campaignId, ...$metadata],
            $idempotencyKey.':'.$workflow->id);
    }

    public function stopEnrollment(string $tenantId, string $enrollmentId, string $reason, array $metadata = []): void
    {
        $enrollment = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $enrollmentId)->first(['campaign_id','company_id']);
        if (! $enrollment) return;
        $workflows = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where(function ($query) use ($enrollmentId, $enrollment): void {
            $query->where('enrollment_id', $enrollmentId)->orWhere(function ($nested) use ($enrollment): void {
                $nested->where('campaign_id', $enrollment->campaign_id)->where('company_id', $enrollment->company_id)->whereNull('enrollment_id');
            });
        })->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->get(['id']);
        foreach ($workflows as $workflow) {
            $event = 'workflow_stopped_'.$reason;
            $this->append($tenantId, $workflow->id, $event, 'domain', ['enrollment_id' => $enrollmentId, 'reason_code' => $reason, ...$metadata],
                'stop:enrollment:'.$enrollmentId.':'.$reason.':'.$workflow->id);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        }
    }

    public function stopOpportunity(string $tenantId, string $opportunityId, string $reason): void
    {
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('opportunity_id', $opportunityId)
            ->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->orderByDesc('updated_at')->first();
        if ($workflow) {
            $this->append($tenantId, $workflow->id, 'workflow_stopped_'.$reason, 'domain', ['opportunity_id' => $opportunityId, 'reason_code' => $reason],
                'stop:opportunity:'.$opportunityId.':'.$reason);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        }
    }

    public function stopConversation(string $tenantId, string $conversationId, string $reason): void
    {
        $conversation = DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $conversationId)->first(['company_id']);
        if (! $conversation) return;
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value]);
        $exact = (clone $query)->where('conversation_id', $conversationId)->first();
        $workflow = $exact ?? $query->where('company_id', $conversation->company_id)->orderByDesc('updated_at')->first();
        if ($workflow) {
            $this->append($tenantId, $workflow->id, 'workflow_stopped_'.$reason, 'domain', ['conversation_id' => $conversationId, 'reason_code' => $reason],
                'stop:conversation:'.$conversationId.':'.$reason);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        }
    }

    public function stopTenant(string $tenantId, string $reason = 'tenant_suspended'): void
    {
        foreach (DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->get(['id']) as $workflow) {
            $this->append($tenantId, $workflow->id, 'workflow_stopped_'.$reason, 'domain', ['reason_code' => $reason], 'stop:tenant:'.$tenantId.':'.$reason.':'.$workflow->id);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        }
    }

    public function recordCompanyEvent(string $tenantId, string $companyId, string $event, string $idempotencyKey, array $metadata = []): void
    {
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->orderByDesc('updated_at')->first();
        if ($workflow) $this->append($tenantId, $workflow->id, $event, 'domain', $metadata, $idempotencyKey);
    }

    public function recordOpportunityEvent(string $tenantId, string $opportunityId, string $event, string $idempotencyKey, array $metadata = []): void
    {
        $opportunity = DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $opportunityId)->first(['company_id','conversation_id']);
        if (! $opportunity) return;
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->whereNotIn('status', [WorkflowStatus::Cancelled->value, WorkflowStatus::Completed->value]);
        if ($opportunity->conversation_id) $query->where(function ($q) use ($opportunity, $opportunityId): void { $q->where('conversation_id', $opportunity->conversation_id)->orWhere('opportunity_id', $opportunityId); });
        else $query->where(function ($q) use ($opportunity, $opportunityId): void { $q->where('company_id', $opportunity->company_id)->orWhere('opportunity_id', $opportunityId); });
        $workflow = $query->orderByDesc('updated_at')->first();
        if (! $workflow) $workflow = $this->create($tenantId, ['company_id' => $opportunity->company_id, 'conversation_id' => $opportunity->conversation_id, 'opportunity_id' => $opportunityId]);
        DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)->update([
            'opportunity_id' => $opportunityId, 'conversation_id' => $workflow->conversation_id ?? $opportunity->conversation_id, 'updated_at' => now(),
        ]);
        $this->append($tenantId, $workflow->id, $event, 'domain', ['opportunity_id' => $opportunityId, ...$metadata], $idempotencyKey);
    }

    public function ensureEnrollmentWorkflow(string $tenantId, string $companyId, string $campaignId, string $enrollmentId): object
    {
        return DB::transaction(function () use ($tenantId, $companyId, $campaignId, $enrollmentId): object {
            $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('company_id', $companyId)
                ->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->orderByDesc('updated_at')->lockForUpdate()->first();
            if (! $workflow) $workflow = $this->create($tenantId, ['company_id' => $companyId, 'campaign_id' => $campaignId, 'enrollment_id' => $enrollmentId, 'initial_stage' => WorkflowStage::Outreach->value]);
            else {
                DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)->update([
                    'campaign_id' => $workflow->campaign_id ?? $campaignId, 'enrollment_id' => $workflow->enrollment_id ?? $enrollmentId, 'updated_at' => now()]);
                $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)->first();
            }
            $this->append($tenantId, $workflow->id, 'campaign_enrolled', 'domain', ['company_id' => $companyId, 'campaign_id' => $campaignId, 'enrollment_id' => $enrollmentId],
                'campaign-enrollment:'.$enrollmentId);
            return DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)->first();
        });
    }

    public function append(string $tenantId, string $workflowId, string $event, string $source, array $metadata, string $idempotencyKey, ?string $actorId = null, ?string $agentRunId = null): object
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        return DB::transaction(function () use ($tenantId, $workflowId, $event, $source, $metadata, $idempotencyKey, $actorId, $agentRunId): object {
            $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->lockForUpdate()->first();
            abort_unless($workflow, 404);
            $existing = DB::table('workflow_events')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless($existing->workflow_id === $workflowId, 409, 'The idempotency key was used for another workflow.');
                return $existing;
            }
            if (in_array($workflow->status, [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value], true) && $event !== 'workflow_cancelled') {
                throw ValidationException::withMessages(['workflow' => 'Terminal workflows cannot accept new events.']);
            }
            if ($workflow->status === WorkflowStatus::Paused->value && $source === 'agent') {
                throw ValidationException::withMessages(['workflow' => 'Paused workflows cannot accept events until resumed.']);
            }
            $nextCount = (int) $workflow->step_count + 1;
            $stageRunCount = $agentRunId ? DB::table('workflow_events')->where('tenant_id', $tenantId)->where('workflow_id', $workflowId)->where('stage', $workflow->current_stage)->whereNotNull('agent_run_id')->where('agent_run_id', '<>', $agentRunId)->distinct('agent_run_id')->count('agent_run_id') : 0;
            $loopExceeded = $nextCount > self::MAX_STEPS || ($agentRunId && $stageRunCount >= self::MAX_AGENT_RUNS_PER_STAGE);
            $safe = $this->safeMetadata($metadata);
            if ($loopExceeded) {
                $event = 'loop_limit_human_review';
                $safe = ['limit' => $nextCount > self::MAX_STEPS ? 'workflow_steps' : 'agent_runs_per_stage'];
            }
            $current = $workflow->current_stage;
            $status = $workflow->status;
            if ($loopExceeded) { $status = WorkflowStatus::Paused->value; }
            elseif ($event === 'approval_requested') { $status = WorkflowStatus::WaitingApproval->value; }
            elseif ($event === 'external_event_wait') { $status = WorkflowStatus::WaitingExternal->value; }
            elseif ($event === 'workflow_completed') { $status = WorkflowStatus::Completed->value; $current = WorkflowStage::Complete->value; }
            elseif ($event === 'workflow_failed') { $status = WorkflowStatus::Failed->value; }
            elseif ($event === 'agent_run_retryable_failure') { $status = WorkflowStatus::Paused->value; }
            elseif ($event === 'agent_run_nonretryable_failure') { $status = WorkflowStatus::Failed->value; }
            elseif ($event === 'workflow_cancelled') { $status = WorkflowStatus::Cancelled->value; }
            elseif (str_starts_with($event, 'workflow_stopped_')) { $status = WorkflowStatus::Cancelled->value; }
            elseif ($event === 'approval_approved' || $event === 'approval_executed' || $event === 'meeting_booked' || $event === 'proposal_approved' || $event === 'message_sent' || $event === 'message_delivered') { $status = WorkflowStatus::WaitingExternal->value; }
            elseif ($event === 'proposal_generated') { $status = WorkflowStatus::WaitingApproval->value; }
            elseif ($event === 'marketing_draft_created') { $status = WorkflowStatus::WaitingApproval->value; }
            elseif ($event === 'marketing_approved' || $event === 'reply_received' || $event === 'reply_analyzed' || $event === 'sales_analysis_completed' || $event === 'opportunity_created') { $status = WorkflowStatus::Running->value; }
            elseif ($event === 'human_handoff') { $status = WorkflowStatus::Paused->value; $current = WorkflowStage::HumanHandoff->value; }
            elseif ($event === 'approval_rejected') { $status = WorkflowStatus::Paused->value; }
            elseif (in_array($event, ['ai_budget_exceeded','approval_expired','loop_limit_human_review'], true)) { $status = WorkflowStatus::Paused->value; }
            else {
                if (in_array($status, [WorkflowStatus::Pending->value, WorkflowStatus::WaitingExternal->value], true)) $status = WorkflowStatus::Running->value;
            }
            $stageForEvent = $this->transitions->targetForEvent($event);
            if ($stageForEvent && $current !== WorkflowStage::HumanHandoff->value) {
                $currentStage = WorkflowStage::from($current);
                if (! $this->transitions->isBehindCurrentStage($currentStage, $stageForEvent)) {
                    $this->transitions->assertAllowed($currentStage, $stageForEvent);
                    $current = $stageForEvent->value;
                }
            }
            $eventId = (string) Str::uuid();
            DB::table('workflow_events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'workflow_id' => $workflowId,
                'stage' => $workflow->current_stage, 'event' => $event, 'source' => mb_substr($source, 0, 48), 'agent_run_id' => $agentRunId,
                'actor_user_id' => $actorId, 'correlation_id' => $workflow->correlation_id, 'idempotency_key' => mb_substr($idempotencyKey, 0, 128),
                'safe_metadata' => json_encode($safe, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->update([
                'current_stage' => $current, 'status' => $status, 'step_count' => min($nextCount, 65535),
                'paused_at' => $status === WorkflowStatus::Paused->value ? now() : (in_array($status, [WorkflowStatus::Running->value, WorkflowStatus::WaitingExternal->value, WorkflowStatus::WaitingApproval->value], true) ? null : $workflow->paused_at),
                'completed_at' => $status === WorkflowStatus::Completed->value ? now() : $workflow->completed_at,
                'failed_at' => $status === WorkflowStatus::Failed->value ? now() : ($status === WorkflowStatus::Running->value ? null : $workflow->failed_at), 'updated_at' => now(),
            ]);
            $recorded = DB::table('workflow_events')->where('tenant_id', $tenantId)->where('id', $eventId)->first();
            if (! app()->runningUnitTests() && in_array($event, ['company_discovered', 'website_analysis_completed', 'lead_scored', 'marketing_draft_created',
                'marketing_approved', 'reply_received', 'reply_analyzed', 'sales_analysis_completed', 'opportunity_created',
                'meeting_requested', 'meeting_booked', 'proposal_requested', 'proposal_generated', 'proposal_approved'], true)) {
                \App\Jobs\CoordinateAcquisitionWorkflowEvent::dispatch($tenantId, $eventId)
                    ->onConnection('redis')->onQueue('workflow')->afterCommit();
            }
            return $recorded;
        });
    }

    public function recordAgentRun(string $tenantId, string $agentRunId, string $agentKey, array $input, string $phase, ?string $summary = null, array $output = [], ?string $failureCode = null): void
    {
        if ($agentKey === 'DiscoveryAgent' && $phase === 'completed') {
            foreach (array_slice(array_unique($output['company_ids'] ?? []), 0, 100) as $companyId) {
                $exists = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('company_id', $companyId)
                    ->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value])->exists();
                if (! $exists && DB::table('companies')->where('tenant_id', $tenantId)->where('id', $companyId)->where('status', '!=', 'discovery_candidate')->exists()) {
                    $workflow = $this->create($tenantId, ['company_id' => $companyId]);
                    $this->append($tenantId, $workflow->id, 'company_discovered', 'agent', ['company_id' => $companyId, 'agent_key' => $agentKey, 'agent_run_id' => $agentRunId],
                        'agent-run:'.$agentRunId.':company:'.$companyId, null, $agentRunId);
                }
            }
        }
        $query = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->whereNotIn('status', [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value]);
        if (! empty($input['website_scan_id'])) {
            $companyId = DB::table('website_scans as s')->join('company_websites as w', function ($join): void { $join->on('w.id','=','s.company_website_id')->on('w.tenant_id','=','s.tenant_id'); })
                ->where('s.tenant_id', $tenantId)->where('s.id', $input['website_scan_id'])->value('w.company_id');
            if ($companyId) $input['company_id'] = $companyId;
        }
        if ($agentKey === 'DiscoveryAgent' && $phase === 'completed') return;
        if (! empty($input['company_id'])) $query->where('company_id', $input['company_id']);
        elseif (! empty($input['conversation_id'])) $query->where('conversation_id', $input['conversation_id']);
        else return;
        $workflow = $query->orderByDesc('updated_at')->first();
        if (! $workflow) return;
        $retryableFailure = in_array($failureCode, ['AI_PROVIDER_UNAVAILABLE', 'AGENT_TIMEOUT'], true);
        $event = $phase === 'started' ? 'agent_run_started' : ($phase === 'failed' ? ($retryableFailure ? 'agent_run_retryable_failure' : 'agent_run_nonretryable_failure') : match ($agentKey) {
            'WebsiteIntelligenceAgent' => 'website_analysis_completed', 'LeadScoringAgent' => 'lead_scored', 'MarketingAgent' => 'marketing_draft_created',
            'FollowUpAgent' => 'reply_analyzed', 'SalesAgent' => 'sales_analysis_completed', 'ProposalAgent' => 'proposal_generated', default => 'agent_run_completed',
        });
        $attempt = (int) $workflow->retry_count;
        $this->append($tenantId, $workflow->id, $event, 'agent', ['agent_key' => $agentKey, 'agent_run_id' => $agentRunId,
            'status' => $phase, 'reason_code' => $phase === 'failed' ? $failureCode : ($summary ? 'agent_completed' : null), 'attempt' => $attempt],
            'agent-run:'.$agentRunId.':'.$phase.':'.$attempt, null, $agentRunId);
    }

    public function control(string $tenantId, string $workflowId, string $operation, ?string $actorId = null): object
    {
        return DB::transaction(function () use ($tenantId, $workflowId, $operation, $actorId): object {
            $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->lockForUpdate()->first();
            abort_unless($workflow, 404);
            $values = match ($operation) {
                'pause' => in_array($workflow->status, [WorkflowStatus::Running->value, WorkflowStatus::WaitingApproval->value, WorkflowStatus::WaitingExternal->value], true)
                    ? ['status' => WorkflowStatus::Paused->value, 'paused_at' => now()] : throw ValidationException::withMessages(['workflow' => 'Only active workflows can be paused.']),
                'resume' => $workflow->status === WorkflowStatus::Paused->value && $workflow->current_stage !== WorkflowStage::HumanHandoff->value && (int) $workflow->step_count < self::MAX_STEPS
                    ? ['status' => WorkflowStatus::Running->value, 'paused_at' => null] : throw ValidationException::withMessages(['workflow' => 'Only paused workflows can be resumed.']),
                'cancel' => ! in_array($workflow->status, [WorkflowStatus::Completed->value, WorkflowStatus::Cancelled->value], true)
                    ? ['status' => WorkflowStatus::Cancelled->value] : throw ValidationException::withMessages(['workflow' => 'Workflow is already terminal.']),
                'retry' => $this->retryFailedAgent($tenantId, $workflow, $actorId),
                default => throw ValidationException::withMessages(['operation' => 'Unsupported workflow operation.']),
            };
            DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->update([...$values, 'updated_at' => now()]);
            $event = $operation === 'cancel' ? 'workflow_cancelled' : ($operation === 'pause' ? 'workflow_pause' : 'workflow_'.$operation);
            $this->append($tenantId, $workflowId, $event, 'human', [], $operation.':'.$workflowId.':'.$workflow->updated_at.':'.now()->format('Uu'), $actorId);
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('workflow_id', $workflowId)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $actorId,
                'action' => 'workflow.'.$operation, 'subject_type' => 'acquisition_workflow', 'subject_id' => $workflowId,
                'metadata' => json_encode(['status' => $values['status']]), 'created_at' => now()]);
            return DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->first();
        });
    }

    private function retryFailedAgent(string $tenantId, object $workflow, ?string $actorId): array
    {
        if (! in_array($workflow->status, [WorkflowStatus::Failed->value, WorkflowStatus::Paused->value], true)
            || (int) $workflow->retry_count >= self::MAX_RETRIES) {
            throw ValidationException::withMessages(['workflow' => 'Workflow is not retryable or retry limit was reached.']);
        }
        $lastFailure = DB::table('workflow_events')->where('tenant_id', $tenantId)->where('workflow_id', $workflow->id)
            ->whereIn('event', ['agent_run_retryable_failure', 'agent_run_nonretryable_failure'])->orderByDesc('created_at')->first();
        if (! $lastFailure || $lastFailure->event !== 'agent_run_retryable_failure' || ! $lastFailure->agent_run_id) {
            throw ValidationException::withMessages(['workflow' => 'Only retryable agent failures can be retried.']);
        }
        $run = DB::table('agent_runs')->where('tenant_id', $tenantId)->where('id', $lastFailure->agent_run_id)->lockForUpdate()->first();
        if (! $run || $run->status !== 'failed' || ! $run->input_ciphertext || ! $run->input_hash) {
            throw ValidationException::withMessages(['workflow' => 'The failed agent request is unavailable for a safe retry.']);
        }
        try {
            $inputJson = Crypt::decryptString($run->input_ciphertext);
            $input = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($input) || ! hash_equals((string) $run->input_hash, hash('sha256', $inputJson))) throw new \RuntimeException();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['workflow' => 'The failed agent request could not be verified for retry.']);
        }
        $newRetryCount = (int) $workflow->retry_count + 1;
        DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflow->id)->update([
            'status' => WorkflowStatus::Running->value, 'retry_count' => $newRetryCount, 'failed_at' => null, 'paused_at' => null, 'updated_at' => now(),
        ]);
        \App\Jobs\RunAgentJob::dispatch($tenantId, $run->agent_key, $input, $actorId ?? $run->requested_by, $run->id)->afterCommit();
        return ['status' => WorkflowStatus::Running->value, 'retry_count' => $newRetryCount, 'failed_at' => null];
    }

    private function safeMetadata(array $metadata): array
    {
        $allowed = ['company_id', 'contact_id', 'campaign_id', 'enrollment_id', 'conversation_id', 'opportunity_id', 'proposal_id', 'scheduling_request_id', 'outbound_message_id', 'meeting_id', 'draft_id', 'version', 'intent', 'confidence', 'agent_key', 'agent_run_id', 'status', 'risk', 'policy', 'reason_code', 'attempt', 'limit'];
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, $allowed, true) && (is_scalar($value) || $value === null)) $safe[$key] = is_string($value) ? mb_substr($value, 0, 180) : $value;
        }
        return $safe;
    }
}
