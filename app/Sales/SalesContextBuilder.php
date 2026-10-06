<?php

namespace App\Sales;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

final class SalesContextBuilder
{
    public function build(string $tenantId, string $conversationId): array
    {
        $conversation = Conversation::where('tenant_id', $tenantId)->with(['company', 'contact'])->findOrFail($conversationId);
        $messages = $conversation->messages()->reorder()->orderByDesc('created_at')->limit(16)->get()->reverse()->values()->map(fn ($m) => [
            'id' => (string) $m->id, 'direction' => $m->direction, 'body' => mb_substr($m->content(), 0, 1500), 'created_at' => $m->created_at?->toISOString(),
        ])->all();
        $leadEvidence = DB::table('lead_evidence')->join('lead_insights', function ($join): void {
            $join->on('lead_insights.id', '=', 'lead_evidence.lead_insight_id')->on('lead_insights.tenant_id', '=', 'lead_evidence.tenant_id');
        })->where('lead_evidence.tenant_id', $tenantId)->where('lead_insights.tenant_id', $tenantId)->where('lead_insights.company_id', $conversation->company_id)
            ->orderByDesc('lead_evidence.observed_at')->limit(12)->get(['lead_evidence.id', 'lead_evidence.excerpt', 'lead_insights.statement'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'text' => mb_substr(trim(($row->statement ?? '').' '.($row->excerpt ?? '')), 0, 700)])->all();
        $knowledge = DB::table('tenant_marketing_knowledge')->where('tenant_id', $tenantId)->where('status', 'approved')
            ->whereIn('kind', ['service', 'capability', 'value_proposition', 'proof_point', 'case_study'])
            ->orderBy('kind')->limit(20)->get(['id', 'kind', 'title', 'content'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'kind' => $row->kind, 'title' => $row->title, 'content' => mb_substr($row->content, 0, 900)])->all();
        $policy = DB::table('tenant_sales_policies')->where('tenant_id', $tenantId)->first();
        return [
            'conversation_model' => $conversation,
            'conversation' => ['id' => (string) $conversation->id, 'stage' => $conversation->conversation_stage, 'ownership' => $conversation->ownership_state,
                'company' => ['name' => $conversation->company?->name, 'industry' => $conversation->company?->industry, 'description' => mb_substr((string) $conversation->company?->description, 0, 1200)],
                'contact' => ['name' => $conversation->contact?->name, 'title' => $conversation->contact?->title], 'messages' => $messages],
            'evidence' => $leadEvidence, 'knowledge' => $knowledge,
            'qualification' => $conversation->sales_opportunity_id ? (DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $conversation->sales_opportunity_id)->value('qualification') ?? []) : [],
            'weights' => $policy ? (json_decode($policy->weights, true) ?: []) : [],
            'thresholds' => $policy ? (json_decode($policy->thresholds, true) ?: []) : [],
        ];
    }
}
