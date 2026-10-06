<?php

namespace App\Marketing;

use App\Agents\AgentOrchestrator;
use App\Campaigns\CampaignTemplateRenderer;
use App\Models\CampaignTemplate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MarketingDraftService
{
    public function __construct(private readonly AgentOrchestrator $agents) {}

    public function generate(GenerateMarketingDraftCommand $command): object
    {
        $this->validateCommand($command);
        $existing = DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('idempotency_key', $command->idempotencyKey)->first();
        if ($existing) return $this->present($existing);

        $input = ['company_id' => $command->companyId, 'contact_id' => $command->contactId, 'campaign_id' => $command->campaignId,
            'campaign_objective' => $command->campaignObjective];
        $runKey = 'marketing:'.$command->idempotencyKey;
        $existingRun = DB::table('agent_runs')->where('tenant_id', $command->tenantId)->where('idempotency_key', $runKey)->first();
        if ($existingRun && in_array($existingRun->status, ['running','succeeded','cancelled'], true)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This idempotent generation request has already been processed.']);
        }
        $runId = $existingRun?->id ?? (string) Str::uuid();
        $correlation = $command->correlationId ?? $existingRun?->correlation_id ?? (string) Str::uuid();
        if (! $existingRun) DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $command->tenantId, 'agent_key' => 'MarketingAgent', 'status' => 'queued',
            'requested_by' => $command->actorId, 'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'idempotency_key' => $runKey,
            'correlation_id' => $correlation, 'created_at' => now(), 'updated_at' => now()]);

        try {
            $result = $this->agents->run('MarketingAgent', $command->tenantId, $input, $command->actorId, $runId);
        } catch (\Throwable $error) {
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $command->tenantId, 'actor_user_id' => $command->actorId,
                'action' => 'marketing_draft.generation_failed', 'subject_type' => 'company', 'subject_id' => $command->companyId,
                'request_id' => $correlation, 'metadata' => json_encode(['agent_run_id' => $runId, 'error_code' => 'AGENT_EXECUTION_FAILED']), 'created_at' => now()]);
            throw new MarketingDraftGenerationException($correlation, $error);
        }

        $draft = DB::transaction(function () use ($command, $result, $runId, $correlation): object {
            $company = DB::table('companies')->where('tenant_id', $command->tenantId)->where('id', $command->companyId)->lockForUpdate()->first();
            if (! $company) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $existing = DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('idempotency_key', $command->idempotencyKey)->lockForUpdate()->first();
            if ($existing) return $existing;
            $previous = null;
            if ($command->previousDraftId) {
                $previous = DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('id', $command->previousDraftId)->lockForUpdate()->first();
                if (! $previous || ! in_array($previous->status, ['draft','rejected','superseded'], true)) {
                    throw ValidationException::withMessages(['previous_draft_id' => 'Only an unapproved tenant draft can be regenerated.']);
                }
                DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('id', $previous->id)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            $version = (int) DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('company_id', $command->companyId)->max('version') + 1;
            $id = (string) Str::uuid(); $data = $result->data;
            DB::table('marketing_drafts')->insert(['id' => $id, 'tenant_id' => $command->tenantId, 'company_id' => $command->companyId,
                'contact_id' => $command->contactId, 'campaign_id' => $command->campaignId, 'agent_run_id' => $runId, 'version' => $version,
                'supersedes_id' => $previous?->id, 'subject' => Crypt::encryptString(trim($data['subject'])), 'message' => Crypt::encryptString(trim($data['message'])),
                'reasoning_summary' => $data['reasoning_summary'], 'personalization_points' => json_encode($data['personalization_points']),
                'evidence_references' => json_encode($data['evidence_references']), 'recommended_call_to_action' => $data['recommended_call_to_action'],
                'confidence' => $data['confidence'], 'provider' => $data['provider'], 'model' => $data['model'], 'task_key' => $data['task_key'],
                'prompt_template_id' => $data['prompt_template_id'], 'prompt_version' => $data['prompt_version'], 'correlation_id' => $correlation,
                'idempotency_key' => $command->idempotencyKey, 'status' => 'draft', 'created_by' => $command->actorId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $command->tenantId, 'actor_user_id' => $command->actorId,
                'action' => 'marketing_draft.'.($previous ? 'regenerated' : 'generated'), 'subject_type' => 'marketing_draft', 'subject_id' => $id,
                'request_id' => $command->requestId ?? $correlation, 'metadata' => json_encode(['agent_run_id' => $runId, 'prompt_version' => $data['prompt_version'],
                    'task' => $data['task_key'], 'provider' => $data['provider'], 'model' => $data['model'], 'correlation_id' => $correlation]), 'created_at' => now()]);
            return DB::table('marketing_drafts')->where('tenant_id', $command->tenantId)->where('id', $id)->first();
        });

        $workflowId = $command->workflowId ?? DB::table('acquisition_workflows')->where('tenant_id', $command->tenantId)
            ->where('company_id', $command->companyId)->whereNotIn('status', ['COMPLETED','CANCELLED'])->orderByDesc('updated_at')->value('id');
        if ($workflowId) $this->recordWorkflowEvent($command->tenantId, $workflowId, $draft, $runId);
        return $this->present($draft);
    }

    public function approve(string $tenantId, string $draftId, string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $draftId, $actorId): object {
            $draft = DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->lockForUpdate()->first();
            if (! $draft) throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            if ($draft->status !== 'draft') throw ValidationException::withMessages(['draft' => 'Only a draft can be approved.']);
            $subject = Crypt::decryptString($draft->subject); $message = Crypt::decryptString($draft->message);
            app(CampaignTemplateRenderer::class)->render($subject, $message, ['contact_name' => 'Contact', 'contact_first_name' => 'Contact', 'company_name' => 'Company',
                'website' => 'https://example.test', 'sender_name' => 'Yaandu']);
            DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->update(['status' => 'approved', 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'updated_at' => now()]);
            $templateId = null;
            if ($draft->campaign_id) {
                $template = CampaignTemplate::create(['tenant_id' => $tenantId, 'campaign_id' => $draft->campaign_id, 'name' => 'Approved AI draft '.substr($draft->id, 0, 8).' v'.$draft->version,
                    'channel' => 'email', 'subject' => $subject, 'body' => $message, 'status' => 'approved', 'version' => $draft->version]);
                $templateId = $template->id;
                DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->update(['campaign_template_id' => $templateId]);
            }
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $actorId, 'action' => 'marketing_draft.approved',
                'subject_type' => 'marketing_draft', 'subject_id' => $draftId, 'metadata' => json_encode(['campaign_template_id' => $templateId]), 'created_at' => now()]);
            $approved = DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->first();
            app(\App\Orchestration\WorkflowService::class)->recordCompanyEvent($tenantId, $approved->company_id, 'marketing_approved',
                'marketing-approved:'.$approved->id, ['draft_id' => $approved->id, 'campaign_id' => $approved->campaign_id]);
            return $this->present($approved);
        });
    }

    public function reject(string $tenantId, string $draftId, string $actorId, ?string $requestId = null): object
    {
        return DB::transaction(function () use ($tenantId, $draftId, $actorId, $requestId): object {
            $draft = DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->lockForUpdate()->first();
            if (! $draft || $draft->status !== 'draft') throw ValidationException::withMessages(['draft' => 'Only a draft can be rejected.']);
            DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)
                ->update(['status' => 'rejected', 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $actorId,
                'action' => 'marketing_draft.rejected', 'subject_type' => 'marketing_draft', 'subject_id' => $draftId,
                'request_id' => $requestId, 'metadata' => json_encode([]), 'created_at' => now()]);
            return $this->present(DB::table('marketing_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->first());
        });
    }

    private function validateCommand(GenerateMarketingDraftCommand $command): void
    {
        if ($command->idempotencyKey === '' || strlen($command->idempotencyKey) > 128) throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        if (! DB::table('companies')->where('tenant_id', $command->tenantId)->where('id', $command->companyId)->exists()) {
            throw ValidationException::withMessages(['company_id' => 'The selected company is not available in this tenant.']);
        }
        if ($command->contactId && ! DB::table('contacts')->where('tenant_id', $command->tenantId)->where('company_id', $command->companyId)->where('id', $command->contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'The selected contact is not available for this company.']);
        }
        if ($command->campaignId && ! DB::table('campaigns')->where('tenant_id', $command->tenantId)->where('id', $command->campaignId)->exists()) {
            throw ValidationException::withMessages(['campaign_id' => 'The selected campaign is not available in this tenant.']);
        }
        if ($command->workflowId && ! DB::table('acquisition_workflows')->where('tenant_id', $command->tenantId)->where('id', $command->workflowId)->where('company_id', $command->companyId)->exists()) {
            throw ValidationException::withMessages(['workflow_id' => 'The selected workflow is not available for this company.']);
        }
    }

    private function recordWorkflowEvent(string $tenantId, string $workflowId, object $draft, string $runId): void
    {
        app(\App\Orchestration\WorkflowService::class)->append($tenantId, $workflowId, 'marketing_draft_created', 'application',
            ['company_id' => $draft->company_id, 'campaign_id' => $draft->campaign_id, 'draft_id' => $draft->id, 'agent_run_id' => $runId, 'version' => $draft->version],
            'marketing-draft:'.$draft->id);
    }

    private function present(object $draft): object
    {
        $draft->subject = Crypt::decryptString($draft->subject); $draft->message = Crypt::decryptString($draft->message);
        return $draft;
    }
}
