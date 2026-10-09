<?php

namespace App\Sales;

use App\Agents\AgentOrchestrator;
use App\Models\Conversation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SalesExecutionService
{
    public function __construct(private readonly AgentOrchestrator $agents, private readonly QualificationScorer $scorer, private readonly SalesPolicy $policy) {}

    public function analyze(string $tenantId, string $conversationId, ?string $actorId, string $key, bool $allowHumanAssistantDraft = false): array
    {
        $conversation = Conversation::where('tenant_id', $tenantId)->findOrFail($conversationId);
        $key = substr('sales:'.$conversationId.':'.$key, 0, 128);
        $existing = DB::table('sales_analyses')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
        if ($existing) return $this->presentAnalysis($existing);
        if ((($conversation->ownership_state ?? 'AI_ACTIVE') === 'HUMAN_ACTIVE' && ! $allowHumanAssistantDraft) || $conversation->status?->value === 'resolved') {
            throw new RuntimeException('Sales analysis is disabled while a human owns or has resolved this conversation.');
        }
        $source = DB::table('conversation_messages')->where('tenant_id', $tenantId)->where('conversation_id', $conversationId)->where('direction', 'inbound')->latest('created_at')->first(['id']);
        if (! $source) throw new RuntimeException('An inbound prospect message is required for sales analysis.');
        $run = DB::table('agent_runs')->where('tenant_id', $tenantId)->where('agent_key', 'SalesAgent')->where('idempotency_key', $key)->first();
        if ($run && in_array($run->status, ['queued', 'running', 'succeeded', 'cancelled'], true)) throw new RuntimeException('This sales analysis is already processing or has completed.');
        $runId = $run?->id ?? (string) Str::uuid();
        if (! $run) DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'agent_key' => 'SalesAgent', 'status' => 'queued', 'requested_by' => $actorId,
            'input_hash' => hash('sha256', $conversationId.':'.$source->id), 'idempotency_key' => $key, 'correlation_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->agents->run('SalesAgent', $tenantId, ['conversation_id' => $conversationId], $actorId, $runId)->data;
        $run = DB::table('agent_runs')->where('tenant_id', $tenantId)->where('id', $runId)->first();
        try { return DB::transaction(function () use ($tenantId, $conversationId, $actorId, $key, $runId, $run, $result, $source, $allowHumanAssistantDraft): array {
            $conversation = Conversation::where('tenant_id', $tenantId)->whereKey($conversationId)->lockForUpdate()->firstOrFail();
            if (($conversation->ownership_state ?? 'AI_ACTIVE') === 'HUMAN_ACTIVE' && ! $allowHumanAssistantDraft) throw new RuntimeException('A human took over this conversation during analysis.');
            $opportunity = $conversation->sales_opportunity_id ? DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $conversation->sales_opportunity_id)->lockForUpdate()->first() : null;
            if (! $opportunity) {
                $opportunityId = (string) Str::uuid();
                DB::table('sales_opportunities')->insert(['id' => $opportunityId, 'tenant_id' => $tenantId, 'company_id' => $conversation->company_id,
                    'contact_id' => $conversation->contact_id, 'conversation_id' => $conversationId, 'owner_user_id' => $conversation->owner_user_id,
                    'stage' => 'NEW', 'status' => 'open', 'qualification_score' => 0, 'qualification_level' => 'LOW', 'qualification' => json_encode([]), 'source' => 'conversation', 'created_at' => now(), 'updated_at' => now()]);
                $conversation->sales_opportunity_id = $opportunityId;
                $opportunity = DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $opportunityId)->first();
                $this->activity($tenantId, $opportunityId, 'created', $actorId, $runId, $run->correlation_id, []);
            }
            $policy = DB::table('tenant_sales_policies')->where('tenant_id', $tenantId)->first();
            app(\App\Orchestration\WorkflowService::class)->recordOpportunityEvent($tenantId, $opportunity->id, 'opportunity_created',
                'opportunity-created:'.$opportunity->id, ['conversation_id' => $conversationId, 'agent_run_id' => $runId]);
            $highValuePolicy = $policy && $policy->high_value_handoff ? (json_decode($policy->high_value_handoff, true) ?: []) : [];
            $requiresHumanReview = (bool) $result['requires_human_review'] || (isset($highValuePolicy['minimum_value']) && $opportunity->value !== null
                && strtoupper((string) ($opportunity->currency ?? '')) === strtoupper((string) ($highValuePolicy['currency'] ?? ''))
                && (float) $opportunity->value >= (float) $highValuePolicy['minimum_value']);
            $qualificationLevels = array_map(fn ($item) => $item['level'], $result['qualification']);
            $score = $this->scorer->score($qualificationLevels, $policy ? (json_decode($policy->weights, true) ?: []) : [], $policy ? (json_decode($policy->thresholds, true) ?: []) : []);
            $qualification = ['dimensions' => $result['qualification'], 'score' => $score, 'updated_at' => now()->toISOString()];
            $humanAssisted = app(\App\SalesIntelligence\SalesIntelligenceMode::class)->humanAssisted($tenantId);
            DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $opportunity->id)->update(['qualification' => json_encode($qualification),
                'qualification_score' => $score['score'], 'qualification_level' => $score['level'],
                // Agent qualification remains advisory until a salesperson explicitly marks the opportunity qualified.
                'qualified_at' => $humanAssisted ? $opportunity->qualified_at : ($score['score'] >= 60 ? ($opportunity->qualified_at ?? now()) : $opportunity->qualified_at), 'updated_at' => now()]);
            $this->activity($tenantId, $opportunity->id, 'qualification_updated', $actorId, $runId, $run->correlation_id, ['score' => $score['score'], 'level' => $score['level']]);
            if (! $humanAssisted && $score['score'] >= 60) $this->activity($tenantId, $opportunity->id, 'marked_qualified', $actorId, $runId, $run->correlation_id, ['score' => $score['score']]);
            $conversation->intent = $result['intent'];
            $conversation->intent_confidence = $result['confidence'];
            $conversation->conversation_stage = $opportunity->stage;
            if ($requiresHumanReview && ($conversation->ownership_state ?? 'AI_ACTIVE') !== 'HUMAN_ACTIVE') {
                $conversation->ownership_state = 'HUMAN_REVIEW';
                $conversation->status = 'human_review';
                $conversation->handoff_reason = strtolower($result['intent']);
                $conversation->handed_off_at = now();
            }
            $conversation->correlation_id = $run->correlation_id;
            $conversation->save();
            $this->activity($tenantId, $opportunity->id, 'qualification_updated', $actorId, $runId, $run->correlation_id, ['intent' => $result['intent'], 'stage' => $opportunity->stage]);
            $analysisId = (string) Str::uuid();
            DB::table('sales_analyses')->insert(['id'=>$analysisId,'tenant_id'=>$tenantId,'conversation_id'=>$conversationId,'sales_opportunity_id'=>$opportunity->id,
                'source_message_id'=>$source->id,'agent_run_id'=>$runId,'idempotency_key'=>$key,'intent'=>$result['intent'],'risk_level'=>$result['risk_level'],
                'confidence'=>$result['confidence'],'qualification_snapshot'=>json_encode($qualification),'missing_information'=>json_encode($result['missing_information']),
                'evidence_references'=>json_encode($result['evidence_references']),'knowledge_references'=>json_encode($result['knowledge_references']),
                'reasoning_summary'=>$result['reasoning_summary'],'requires_human_review'=>$requiresHumanReview,'recommended_action'=>$result['recommended_action'],
                'provider'=>$result['provider'],'model'=>$result['model'],'prompt_template_id'=>$result['prompt_template_id'],'prompt_version'=>$result['prompt_version'],
                'correlation_id'=>$run->correlation_id,'created_at'=>now(),'updated_at'=>now()]);
            $draftId = null;
            if ($result['draft_response'] !== '') {
                DB::table('sales_drafts')->where('tenant_id',$tenantId)->where('conversation_id',$conversationId)->where('status','pending')
                    ->update(['status'=>'superseded','updated_at'=>now()]);
                $draftId = (string) Str::uuid();
                DB::table('sales_drafts')->insert(['id' => $draftId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'sales_opportunity_id' => $opportunity->id, 'sales_analysis_id'=>$analysisId,
                    'agent_run_id' => $runId, 'body_ciphertext' => Crypt::encryptString($result['draft_response']), 'evidence_references' => json_encode($result['evidence_references']),
                    'knowledge_references' => json_encode($result['knowledge_references']), 'missing_information' => json_encode($result['missing_information']),
                    'qualification_snapshot' => json_encode($qualification), 'intent' => $result['intent'], 'risk_level' => $result['risk_level'], 'confidence' => $result['confidence'],
                    'provider' => $result['provider'], 'model' => $result['model'], 'task_key' => $result['task_key'], 'prompt_template_id' => $result['prompt_template_id'],
                    'prompt_version' => $result['prompt_version'], 'correlation_id' => $run->correlation_id, 'idempotency_key' => $key, 'status' => 'pending',
                    'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                $this->activity($tenantId, $opportunity->id, 'sales_draft_generated', $actorId, $runId, $run->correlation_id, ['draft_id' => $draftId, 'intent' => $result['intent']]);
            }
            if ($requiresHumanReview) $this->activity($tenantId, $opportunity->id, 'human_review_requested', $actorId, $runId, $run->correlation_id, ['intent' => $result['intent']]);
            return ['conversation' => $conversation->fresh(), 'opportunity' => DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $opportunity->id)->first(),
                'qualification' => $qualification, 'sales_draft' => $draftId ? $this->present(DB::table('sales_drafts')->where('tenant_id', $tenantId)->where('id', $draftId)->first()) : null,
                'requires_human_review' => $requiresHumanReview, 'intent' => $result['intent'], 'risk_level' => $result['risk_level'],
                'missing_information' => $result['missing_information'], 'evidence_references' => $result['evidence_references'], 'knowledge_references' => $result['knowledge_references'],
                'reasoning_summary' => $result['reasoning_summary'], 'agent_run_id' => $runId, 'correlation_id' => $run->correlation_id];
        }); } catch (\Throwable $error) {
            DB::table('agent_runs')->where('tenant_id',$tenantId)->where('id',$runId)->where('status','succeeded')->update(['status'=>'failed','error_code'=>'persistence_failed','error_summary'=>'Sales result could not be persisted safely.','finished_at'=>now(),'updated_at'=>now()]);
            $sequence=(int)DB::table('agent_events')->where('tenant_id',$tenantId)->where('agent_run_id',$runId)->max('sequence')+1;
            DB::table('agent_events')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'agent_run_id'=>$runId,'sequence'=>$sequence,'event_key'=>'persistence_failed',
                'payload'=>json_encode(['error_code'=>'persistence_failed','correlation_id'=>$run->correlation_id]),'created_at'=>now()]);
            throw $error;
        }
    }

    private function presentAnalysis(object $analysis): array
    {
        $draft=DB::table('sales_drafts')->where('tenant_id',$analysis->tenant_id)->where('sales_analysis_id',$analysis->id)->first();
        $opportunity=DB::table('sales_opportunities')->where('tenant_id',$analysis->tenant_id)->where('id',$analysis->sales_opportunity_id)->first();
        $conversation=Conversation::where('tenant_id',$analysis->tenant_id)->find($analysis->conversation_id);
        $decode=fn(string $value)=>json_decode($value,true)?:[];
        return ['conversation'=>$conversation,'opportunity'=>$opportunity,'qualification'=>$decode($analysis->qualification_snapshot),'sales_draft'=>$draft?$this->present($draft):null,
            'requires_human_review'=>(bool)$analysis->requires_human_review,'intent'=>$analysis->intent,'risk_level'=>$analysis->risk_level,
            'missing_information'=>$decode($analysis->missing_information),'evidence_references'=>$decode($analysis->evidence_references),'knowledge_references'=>$decode($analysis->knowledge_references),
            'reasoning_summary'=>$analysis->reasoning_summary,'agent_run_id'=>$analysis->agent_run_id,'correlation_id'=>$analysis->correlation_id];
    }

    public function present(object $draft): array
    {
        return ['id' => $draft->id, 'conversation_id' => $draft->conversation_id, 'status' => $draft->status, 'body' => Crypt::decryptString($draft->body_ciphertext),
            'intent' => $draft->intent, 'risk_level' => $draft->risk_level, 'confidence' => (float) $draft->confidence,
            'evidence_references' => json_decode($draft->evidence_references, true) ?: [], 'knowledge_references' => json_decode($draft->knowledge_references, true) ?: [],
            'missing_information' => json_decode($draft->missing_information, true) ?: [], 'agent_run_id' => $draft->agent_run_id, 'correlation_id' => $draft->correlation_id];
    }

    private function activity(string $tenantId, string $opportunityId, string $type, ?string $actorId, string $runId, string $correlation, array $details): void
    {
        DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'sales_opportunity_id' => $opportunityId,
            'actor_user_id' => $actorId, 'agent_run_id' => $runId, 'correlation_id' => $correlation, 'activity_type' => $type,
            'details' => json_encode($details), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
