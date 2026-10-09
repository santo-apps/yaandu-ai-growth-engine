<?php

namespace App\Orchestration;

use App\Jobs\ProcessFollowUpReply;
use App\Jobs\RunAgentJob;
use App\Marketing\GenerateMarketingDraftCommand;
use App\Marketing\MarketingDraftService;
use App\Proposals\GenerateProposalCommand;
use App\Proposals\ProposalGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Resolves lifecycle events to application-owned actions; model output is never used as a dispatch key. */
final class AcquisitionWorkflowCoordinator
{
    public function __construct(private readonly PolicyEngine $policy, private readonly WorkflowService $workflows) {}

    /** @return array{action:?string,decision:string,reason:string} */
    public function resolve(string $tenantId, string $workflowId, string $event, array $metadata = []): array
    {
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->first();
        if (! $workflow) return ['action' => null, 'decision' => 'DENIED', 'reason' => 'workflow_not_found'];
        $stop = $this->stopReason($tenantId, $workflow);
        if ($stop !== null) return ['action' => null, 'decision' => 'DENIED', 'reason' => $stop];

        $action = match ($event) {
            'company_discovered' => 'RUN_WEBSITE_ANALYSIS',
            'website_analysis_completed' => 'RUN_LEAD_SCORING',
            'lead_scored' => $this->outreachEligible($tenantId, $workflow) ? 'GENERATE_MARKETING_DRAFT' : null,
            'reply_received' => 'GENERATE_FOLLOW_UP',
            'reply_analyzed' => 'RUN_SALES_ANALYSIS',
            'opportunity_created' => 'REQUEST_MEETING',
            'proposal_requested' => 'GENERATE_PROPOSAL',
            'proposal_approved' => null,
            'message_delivered', 'meeting_requested', 'meeting_booked', 'proposal_generated',
            'marketing_draft_created', 'marketing_approved' => null,
            default => null,
        };
        if ($action === null) return ['action' => null, 'decision' => 'WAIT', 'reason' => $event === 'lead_scored' ? 'outreach_ineligible' : 'external_or_human_boundary'];
        $policy = $this->policy->evaluate($tenantId, $action);
        return ['action' => $action, 'decision' => $policy['policy'], 'reason' => 'deterministic_event_mapping'];
    }

    /** Resolves and performs only actions with an existing deterministic executor. Replays are keyed in SQL. */
    public function consume(string $tenantId, string $workflowId, string $event, array $metadata = [], ?string $actorId = null): array
    {
        $resolved = $this->resolve($tenantId, $workflowId, $event, $metadata);
        $action = $resolved['action'];
        if ($action === null) return $resolved;
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenantId)->where('id', $workflowId)->first();
        if (! $workflow) return ['action' => null, 'decision' => 'DENIED', 'reason' => 'workflow_not_found'];

        if ($action === 'REQUEST_MEETING' && $resolved['decision'] === ActionPolicy::ApprovalRequired->value) {
            $conversationId = $metadata['conversation_id'] ?? $workflow->conversation_id;
            if (! $conversationId || ! DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $conversationId)->exists()) {
                return ['action' => $action, 'decision' => 'WAIT', 'reason' => 'conversation_required'];
            }
            $approval = app(ApprovalService::class)->request($tenantId, $workflowId, 'REQUEST_MEETING',
                ['target_type' => 'conversation', 'target_id' => $conversationId], 'A human must approve the meeting request.',
                'coordinator:meeting:'.$workflowId);
            return ['action' => $action, 'decision' => ActionPolicy::ApprovalRequired->value, 'reason' => 'approval_created', 'approval_id' => $approval->id];
        }
        if ($resolved['decision'] !== ActionPolicy::AutoAllowed->value) return $resolved;

        if ($action === 'RUN_WEBSITE_ANALYSIS') return $this->dispatchWebsiteAnalysis($tenantId, $workflow, $actorId);
        if ($action === 'GENERATE_MARKETING_DRAFT') {
            $key = 'coordinator:'.$workflowId.':marketing-draft';
            $draft = app(MarketingDraftService::class)->generate(new GenerateMarketingDraftCommand(
                tenantId: $tenantId, companyId: $workflow->company_id, contactId: $workflow->contact_id,
                campaignId: $workflow->campaign_id, campaignObjective: null, actorId: $actorId,
                idempotencyKey: $key, workflowId: $workflowId, correlationId: $workflow->correlation_id,
            ));
            return [...$resolved, 'draft_id' => $draft->id, 'replayed' => false];
        }
        if ($action === 'GENERATE_PROPOSAL') {
            $proposalId = $metadata['proposal_id'] ?? null;
            if (! $proposalId) return ['action' => $action, 'decision' => 'WAIT', 'reason' => 'proposal_required'];
            $key = 'coordinator:'.$workflowId.':proposal:'.$proposalId;
            $version = app(ProposalGenerationService::class)->generate(new GenerateProposalCommand(
                tenantId: $tenantId, proposalId: $proposalId, actorId: $actorId, idempotencyKey: $key,
            ));
            return [...$resolved, 'proposal_version_id' => $version->id, 'replayed' => false];
        }
        if ($action === 'GENERATE_FOLLOW_UP' && $workflow->conversation_id) {
            ProcessFollowUpReply::dispatch($tenantId, $workflow->conversation_id)->afterCommit();
            return $resolved;
        }

        $agent = match ($action) {
            'RUN_LEAD_SCORING' => 'LeadScoringAgent',
            'RUN_SALES_ANALYSIS' => 'SalesAgent',
            default => null,
        };
        if (! $agent) return ['action' => $action, 'decision' => 'WAIT', 'reason' => 'no_deterministic_executor'];
        $input = match ($agent) {
            'LeadScoringAgent' => ['company_id' => $workflow->company_id],
            'MarketingAgent' => ['company_id' => $workflow->company_id, 'contact_id' => $workflow->contact_id, 'campaign_id' => $workflow->campaign_id],
            default => ['conversation_id' => $workflow->conversation_id],
        };
        if (in_array(null, $input, true)) return ['action' => $action, 'decision' => 'WAIT', 'reason' => 'required_domain_context_missing'];
        $key = 'coordinator:'.$workflowId.':'.$agent;
        $run = DB::table('agent_runs')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
        if ($run) return [...$resolved, 'agent_run_id' => $run->id, 'replayed' => true];
        $runId = (string) Str::uuid();
        $correlation = $workflow->correlation_id ?: (string) Str::uuid();
        DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'agent_key' => $agent, 'status' => 'queued',
            'requested_by' => $actorId, 'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            'idempotency_key' => $key, 'correlation_id' => $correlation, 'created_at' => now(), 'updated_at' => now()]);
        RunAgentJob::dispatch($tenantId, $agent, $input, $actorId, $runId)->afterCommit();
        return [...$resolved, 'agent_run_id' => $runId, 'replayed' => false];
    }

    private function dispatchWebsiteAnalysis(string $tenantId, object $workflow, ?string $actorId): array
    {
        $website = DB::table('company_websites')->where('tenant_id', $tenantId)->where('company_id', $workflow->company_id)->orderBy('created_at')->first();
        if (! $website) return ['action' => 'RUN_WEBSITE_ANALYSIS', 'decision' => 'WAIT', 'reason' => 'website_missing'];
        $key = 'coordinator:'.$workflow->id.':WebsiteIntelligenceAgent';
        $run = DB::table('agent_runs')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
        if ($run) return ['action' => 'RUN_WEBSITE_ANALYSIS', 'decision' => ActionPolicy::AutoAllowed->value, 'reason' => 'idempotent_replay', 'agent_run_id' => $run->id];
        $scanId = (string) Str::uuid(); $runId = (string) Str::uuid();
        DB::transaction(function () use ($tenantId, $website, $scanId, $runId, $key, $workflow, $actorId): void {
            DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenantId, 'company_website_id' => $website->id, 'status' => 'queued',
                'max_depth' => 2, 'max_pages' => 30, 'crawler_version' => 'http-v1', 'policy_snapshot' => json_encode(['respect_robots' => true]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'queued',
                'requested_by' => $actorId, 'input_hash' => hash('sha256', $scanId), 'idempotency_key' => $key, 'correlation_id' => $workflow->correlation_id,
                'created_at' => now(), 'updated_at' => now()]);
        });
        \App\Jobs\ScanWebsiteJob::dispatch($tenantId, $website->id, $scanId, $runId, $actorId)->afterCommit();
        return ['action' => 'RUN_WEBSITE_ANALYSIS', 'decision' => ActionPolicy::AutoAllowed->value, 'reason' => 'dispatched', 'agent_run_id' => $runId];
    }

    private function outreachEligible(string $tenantId, object $workflow): bool
    {
        if (app(\App\SalesIntelligence\SalesIntelligenceMode::class)->humanAssisted($tenantId)) {
            // A score is advisory. Only an explicit human priority and service decision may open draft planning.
            $row = DB::table('prospect_import_rows')->where('tenant_id', $tenantId)->where('company_id', $workflow->company_id)->orderByDesc('processed_at')->value('id');
            if (! $row) return false;
            $decision = DB::table('pilot_human_decisions')->where('tenant_id', $tenantId)->where('prospect_import_row_id', $row)->orderByDesc('decided_at')->orderByDesc('id')->first();
            if (! $decision || ! in_array($decision->priority, ['high', 'medium'], true) || $decision->service_decision !== 'selected') return false;
        }
        $score = DB::table('lead_scores')->where('tenant_id', $tenantId)->where('company_id', $workflow->company_id)->orderByDesc('scored_at')->value('score');
        return $score !== null && (int) $score >= (int) config('orchestration.outreach_score_threshold', 70);
    }

    private function stopReason(string $tenantId, object $workflow): ?string
    {
        if (in_array($workflow->status, [WorkflowStatus::Paused->value, WorkflowStatus::Cancelled->value, WorkflowStatus::Completed->value, WorkflowStatus::Failed->value], true)) return 'workflow_not_runnable';
        if ($workflow->current_stage === WorkflowStage::HumanHandoff->value) return 'human_handoff';
        if (DB::table('tenants')->where('id', $tenantId)->where('status', '!=', 'active')->exists()) return 'tenant_suspended';
        if ($workflow->campaign_id && DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $workflow->campaign_id)->where('status', 'cancelled')->exists()) return 'campaign_cancelled';
        if ($workflow->enrollment_id) {
            $enrollment = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $workflow->enrollment_id)->first(['status','contact_method_id']);
            if ($enrollment && in_array($enrollment->status, ['stopped','completed','suppressed','handed_off','unsubscribed','bounced'], true)) return 'enrollment_stopped';
            if ($enrollment?->contact_method_id && app(\App\Campaigns\SuppressionChecker::class)->isMethodSuppressed($tenantId, $enrollment->contact_method_id)) return 'contact_suppressed';
        }
        if ($workflow->opportunity_id && DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $workflow->opportunity_id)->whereIn('stage', ['CLOSED','NOT_QUALIFIED'])->exists()) return 'opportunity_closed';
        if ($workflow->conversation_id) {
            $conversation = DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $workflow->conversation_id)->first(['status','ownership_state','intent']);
            if ($conversation && (in_array($conversation->status, ['resolved','human_active'], true) || $conversation->ownership_state === 'HUMAN_ACTIVE')) return 'human_owned_or_resolved';
            if ($conversation && in_array(strtolower((string) $conversation->intent), ['unsubscribe','not_interested','wrong_contact'], true)) return 'terminal_contact_intent';
        }
        return null;
    }
}
