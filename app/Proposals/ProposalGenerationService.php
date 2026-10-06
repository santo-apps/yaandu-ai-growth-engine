<?php

namespace App\Proposals;

use App\Agents\AgentOrchestrator;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Orchestration\WorkflowService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProposalGenerationService
{
    public function __construct(private readonly AgentOrchestrator $agents, private readonly WorkflowService $workflows) {}

    public function generate(GenerateProposalCommand $command): ProposalVersion
    {
        if ($command->idempotencyKey === '' || strlen($command->idempotencyKey) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
        $existing = ProposalVersion::where('tenant_id', $command->tenantId)->where('proposal_id', $command->proposalId)
            ->where('idempotency_key', $command->idempotencyKey)->first();
        if ($existing) return $existing;

        $record = Proposal::where('tenant_id', $command->tenantId)->findOrFail($command->proposalId);
        if (! in_array($record->status->value, [ProposalStatus::Draft->value, ProposalStatus::CommercialInputRequired->value,
            ProposalStatus::ReviewRequired->value, ProposalStatus::Rejected->value], true)) {
            throw ValidationException::withMessages(['proposal' => 'Proposal cannot be generated in its current state.']);
        }
        $requirements = DB::table('proposal_requirements')->where('tenant_id', $command->tenantId)
            ->where('proposal_id', $record->id)->latest()->first();
        if (! $requirements) throw ValidationException::withMessages(['proposal' => 'Proposal requirements are missing.']);
        $runInput = ['proposal_id' => $record->id, 'generation_key' => $command->idempotencyKey];
        try {
            $result = $this->agents->run('ProposalAgent', $command->tenantId, $runInput, $command->actorId);
        } catch (Throwable $error) {
            Proposal::where('tenant_id', $command->tenantId)->where('id', $command->proposalId)
                ->update(['safe_generation_error' => 'Proposal generation failed. Retry after checking AI configuration.']);
            throw $error;
        }
        $inputHash = hash('sha256', json_encode($runInput, JSON_THROW_ON_ERROR));
        $run = DB::table('agent_runs')->where('tenant_id', $command->tenantId)->where('agent_key', 'ProposalAgent')
            ->where('requested_by', $command->actorId)->where('input_hash', $inputHash)->orderByDesc('created_at')->first();
        $data = $result->data;
        $grounding = $result->evidence['grounding_snapshot'] ?? [];
        $knowledge = collect($grounding['approved_knowledge'] ?? [])->keyBy('id');
        $caseStudies = collect($data['case_study_references'])->map(fn ($id) => $knowledge->get($id))
            ->filter(fn ($entry) => $entry && in_array($entry['kind'], ['case_study', 'proof_point'], true))
            ->map(fn ($entry) => ['title' => $entry['title'], 'content' => $entry['content']])->values()->all();
        $draftContent = [...collect($data)->except(['provider', 'model', 'prompt_template_id', 'prompt_version', 'recommended_scope'])->all(),
            'client_visible_terms' => $record->terms, 'case_studies' => $caseStudies];

        $version = DB::transaction(function () use ($command, $record, $requirements, $run, $data, $grounding, $draftContent): ProposalVersion {
            $locked = Proposal::where('tenant_id', $command->tenantId)->lockForUpdate()->findOrFail($record->id);
            $existing = ProposalVersion::where('tenant_id', $command->tenantId)->where('proposal_id', $record->id)
                ->where('idempotency_key', $command->idempotencyKey)->lockForUpdate()->first();
            if ($existing) return $existing;
            $next = (int) ProposalVersion::where('tenant_id', $record->tenant_id)->where('proposal_id', $record->id)->max('version') + 1;
            $version = ProposalVersion::create(['tenant_id' => $command->tenantId, 'proposal_id' => $record->id, 'version' => $next,
                'agent_run_id' => $run?->id, 'prompt_template_id' => $data['prompt_template_id'], 'prompt_version' => $data['prompt_version'],
                'provider' => $data['provider'], 'model' => $data['model'], 'requirements_snapshot' => (array) $requirements,
                'source_snapshot' => ['ciphertext' => Crypt::encryptString(json_encode($grounding, JSON_THROW_ON_ERROR))], 'draft_content' => $draftContent,
                'recommended_scope' => $data['recommended_scope'], 'requested_scope' => json_decode($requirements->requested_services, true) ?: [],
                'status' => 'review_required', 'created_by' => $command->actorId, 'correlation_id' => $run?->correlation_id,
                'idempotency_key' => $command->idempotencyKey]);
            $hasCommercialInput = DB::table('proposal_items')->where('tenant_id', $command->tenantId)->where('proposal_id', $locked->id)->exists() && (bool) $locked->currency;
            $locked->update(['version' => $next, 'latest_version_id' => $version->id,
                'status' => $hasCommercialInput ? ProposalStatus::ReviewRequired : ProposalStatus::CommercialInputRequired, 'safe_generation_error' => null]);
            $this->activity($command, $locked->sales_opportunity_id, $next > 1 ? 'proposal_regenerated' : 'proposal_generated', $version, $run);
            $this->audit($command, $locked->id, $next > 1 ? 'proposal_regenerated' : 'proposal_generated', ['version' => $next, 'agent_run_id' => $run?->id]);
            return $version;
        });

        $this->workflows->recordOpportunityEvent($command->tenantId, $record->sales_opportunity_id, 'proposal_generated',
            'workflow:proposal-generated:'.$version->id, ['proposal_id' => $record->id, 'version' => $version->version, 'agent_run_id' => $version->agent_run_id]);
        return $version;
    }

    private function activity(GenerateProposalCommand $command, string $opportunity, string $type, ProposalVersion $version, ?object $run): void
    {
        DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $command->tenantId,
            'sales_opportunity_id' => $opportunity, 'activity_type' => $type, 'actor_user_id' => $command->actorId,
            'agent_run_id' => $run?->id, 'correlation_id' => $run?->correlation_id,
            'details' => json_encode(['proposal_id' => $version->proposal_id, 'version' => $version->version]),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function audit(GenerateProposalCommand $command, string $proposalId, string $action, array $metadata): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $command->tenantId, 'actor_user_id' => $command->actorId,
            'action' => $action, 'subject_type' => 'proposal', 'subject_id' => $proposalId, 'request_id' => $command->requestId,
            'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
