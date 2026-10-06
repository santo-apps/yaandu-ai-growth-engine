<?php

namespace App\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApprovalService
{
    public function __construct(private readonly PolicyEngine $policy, private readonly WorkflowService $workflows) {}

    public function request(string $tenantId, string $workflowId, string $action, array $payload, string $reason, string $idempotencyKey, ?string $actorId = null, ?string $agentRunId = null): object
    {
        $decision = $this->policy->evaluate($tenantId, $action);
        if ($decision['policy'] !== ActionPolicy::ApprovalRequired->value) {
            throw ValidationException::withMessages(['action' => 'This action is not eligible for this approval path.']);
        }
        if (in_array($action, ['SEND_OUTREACH', 'SEND_FOLLOW_UP'], true) && (($payload['target_type'] ?? null) !== 'outbound_message' || empty($payload['target_id']))) {
            throw ValidationException::withMessages(['payload' => 'An approved outbound action must bind to an existing outbound message.']);
        }
        if ($action === 'REQUEST_MEETING' && (($payload['target_type'] ?? null) !== 'conversation' || empty($payload['target_id']))) {
            throw ValidationException::withMessages(['payload' => 'A meeting request must bind to an existing conversation.']);
        }
        if (! in_array($action, ['SEND_OUTREACH', 'SEND_FOLLOW_UP', 'REQUEST_MEETING'], true)) {
            throw ValidationException::withMessages(['action' => 'No deterministic approval executor is enabled for this action.']);
        }
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->first();
        abort_unless($workflow, 404);
        if ($agentRunId && ! DB::table('agent_runs')->where('tenant_id', $tenantId)->where('id', $agentRunId)->exists()) abort(404);
        $payload = $this->canonical($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return DB::transaction(function () use ($tenantId, $workflowId, $action, $payload, $hash, $reason, $idempotencyKey, $actorId, $agentRunId, $decision): object {
            $existing = DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless($existing->workflow_id === $workflowId && $existing->action === $action && hash_equals($existing->payload_hash, $hash), 409, 'The idempotency key was used for a different approval payload.');
                return $existing;
            }
            $id = (string) Str::uuid();
            DB::table('workflow_approvals')->insert(['id' => $id, 'tenant_id' => $tenantId, 'workflow_id' => $workflowId,
                'action' => $action, 'target_type' => $payload['target_type'] ?? null, 'target_id' => $payload['target_id'] ?? null,
                'requested_by' => $actorId, 'agent_run_id' => $agentRunId, 'risk' => $decision['risk'], 'reason' => mb_substr($reason, 0, 500),
                'payload_snapshot' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'status' => 'PENDING',
                'idempotency_key' => mb_substr($idempotencyKey, 0, 128), 'requested_at' => now(), 'expires_at' => now()->addHours(48),
                'created_at' => now(), 'updated_at' => now()]);
            $this->workflows->append($tenantId, $workflowId, 'approval_requested', 'policy', ['risk' => $decision['risk'], 'policy' => $decision['policy'], 'reason_code' => 'human_approval_required'],
                'approval:requested:'.$id, $actorId, $agentRunId);
            return DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $id)->first();
        });
    }

    public function decide(string $tenantId, string $approvalId, string $decision, string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $approvalId, $decision, $actorId): object {
            $approval = DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->lockForUpdate()->first();
            abort_unless($approval, 404);
            if ($approval->status !== 'PENDING') throw ValidationException::withMessages(['approval' => 'This approval is no longer pending.']);
            if ($approval->expires_at && now()->greaterThan($approval->expires_at)) {
                DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->update(['status' => 'EXPIRED', 'updated_at' => now()]);
                $this->workflows->append($tenantId, $approval->workflow_id, 'approval_expired', 'policy', ['risk' => $approval->risk, 'reason_code' => 'approval_expired'], 'approval:expired:'.$approvalId);
                return DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->first();
            }
            $payload = json_decode($approval->payload_snapshot, true, 32, JSON_THROW_ON_ERROR);
            if (! hash_equals($approval->payload_hash, hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
                throw ValidationException::withMessages(['approval' => 'The approved action payload has changed.']);
            }
            if ($decision === 'approve') {
                $this->revalidateTarget($tenantId, $payload);
                $this->executeApproved($tenantId, $approval->action, $payload, $actorId, $approval->workflow_id, $approvalId);
            }
            $executed = $decision === 'approve' && in_array($approval->action, ['SEND_OUTREACH','SEND_FOLLOW_UP','REQUEST_MEETING'], true);
            $newStatus = $decision === 'approve' ? ($executed ? 'EXECUTED' : 'APPROVED') : 'REJECTED';
            DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->update(['status' => $newStatus, 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'updated_at' => now()]);
            $event = $decision === 'reject' ? 'approval_rejected' : ($executed ? 'approval_executed' : 'approval_approved');
            $this->workflows->append($tenantId, $approval->workflow_id, $event, 'human', ['risk' => $approval->risk, 'policy' => $newStatus],
                'approval:'.$decision.':'.$approvalId, $actorId, $approval->agent_run_id);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $actorId,
                'action' => 'workflow_approval.'.$decision, 'subject_type' => 'workflow_approval', 'subject_id' => $approvalId,
                'metadata' => json_encode(['workflow_id' => $approval->workflow_id, 'action' => $approval->action, 'payload_hash' => $approval->payload_hash]), 'created_at' => now()]);
            return DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->first();
        });
    }

    private function revalidateTarget(string $tenantId, array $payload): void
    {
        $table = match ($payload['target_type'] ?? null) {
            'campaign' => 'campaigns', 'conversation' => 'conversations', 'opportunity' => 'sales_opportunities', 'proposal' => 'proposals', 'enrollment' => 'campaign_recipients', 'outbound_message' => 'outbound_messages',
            default => null,
        };
        if ($table && ! empty($payload['target_id']) && ! DB::table($table)->where('tenant_id', $tenantId)->where('id', $payload['target_id'])->exists()) {
            throw ValidationException::withMessages(['approval' => 'The target is no longer available in this tenant.']);
        }
        if (($payload['target_type'] ?? null) === 'outbound_message') {
            $message = DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('id', $payload['target_id'])->lockForUpdate()->first();
            $campaign = $message ? DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $message->campaign_id)->first() : null;
            $recipient = $message ? DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $message->campaign_recipient_id)->first() : null;
            if (! $message || ! in_array($message->status, ['queued','sending'], true) || ! $campaign || $campaign->status !== 'active' || ! $recipient || $recipient->status !== 'active') {
                throw ValidationException::withMessages(['approval' => 'The outbound message is no longer eligible for dispatch.']);
            }
            if (DB::table('conversations')->where('tenant_id', $tenantId)->where('contact_id', $recipient->contact_id)->whereIn('status', ['human_review','human_active','resolved'])->exists()) {
                throw ValidationException::withMessages(['approval' => 'Human ownership blocks outbound dispatch.']);
            }
            if (app(\App\Campaigns\SuppressionChecker::class)->isMethodSuppressed($tenantId, $message->contact_method_id)) {
                throw ValidationException::withMessages(['approval' => 'Suppression prevents outbound dispatch.']);
            }
        }
        if (($payload['target_type'] ?? null) === 'conversation') {
            $conversation = DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $payload['target_id'])->first();
            $schedulingEnabled = DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->where('enabled', true)->exists();
            $requestExists = DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('conversation_id', $payload['target_id'])
                ->whereIn('status', ['REQUESTED','AWAITING_SELECTION','SELECTED','BOOKING','BOOKED'])->exists();
            if (! $conversation || $conversation->status === 'resolved' || ! $schedulingEnabled || $requestExists) {
                throw ValidationException::withMessages(['approval' => 'Meeting request is no longer eligible under current tenant settings.']);
            }
            if (isset($payload['timezone']) && ! in_array($payload['timezone'], timezone_identifiers_list(), true)) {
                throw ValidationException::withMessages(['approval' => 'The meeting timezone is invalid.']);
            }
        }
    }

    private function executeApproved(string $tenantId, string $action, array $payload, string $actorId, string $workflowId, string $approvalId): void
    {
        if (in_array($action, ['SEND_OUTREACH','SEND_FOLLOW_UP'], true)) {
            // The idempotent delivery job revalidates campaign, suppression, ownership, and send windows immediately before provider access.
            \App\Jobs\SendOutboundMessage::dispatch($tenantId, $payload['target_id'])->afterCommit();
            return;
        }
        if ($action === 'REQUEST_MEETING') {
            $conversation = \App\Models\Conversation::where('tenant_id', $tenantId)->where('id', $payload['target_id'])->firstOrFail();
            $timezone = $payload['timezone'] ?? DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->value('default_timezone') ?? 'UTC';
            app(\App\Scheduling\SchedulingWorkflow::class)->createRequest($tenantId, $conversation, $timezone, (int) $actorId);
        }
    }

    private function canonical(array $payload): array
    {
        foreach ($payload as $key => $value) if (is_array($value)) $payload[$key] = array_is_list($value) ? array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value) : $this->canonical($value);
        if (! array_is_list($payload)) ksort($payload);
        return $payload;
    }
}
