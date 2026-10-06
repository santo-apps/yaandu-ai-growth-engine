<?php

namespace App\Conversations;

use App\Agents\AgentDecisionService;
use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ConversationAnalysisService
{
    public function __construct(
        private readonly ConversationIntentClassifier $classifier,
        private readonly AgentDecisionService $decisions,
    ) {}

    public function analyze(string $tenantId, string $conversationId): array
    {
        $conversation = Conversation::where('tenant_id', $tenantId)->with('company')->findOrFail($conversationId);
        if ($conversation->status === ConversationWorkflowStatus::Resolved) {
            throw new RuntimeException('Resolved conversations cannot be classified.');
        }
        $messages = $conversation->messages()->orderBy('created_at')->get()->map(fn ($message): array => [
            'direction' => $message->direction, 'body' => $message->content(),
        ])->all();
        if (! collect($messages)->contains(fn (array $message): bool => $message['direction'] === 'inbound')) {
            throw new RuntimeException('An inbound customer message is required before analysis.');
        }
        $evidence = DB::table('lead_evidence')->join('lead_insights', 'lead_insights.id', '=', 'lead_evidence.lead_insight_id')
            ->where('lead_insights.tenant_id', $tenantId)->where('lead_insights.company_id', $conversation->company_id)
            ->orderByDesc('lead_evidence.observed_at')->limit(40)->get(['lead_evidence.id', 'lead_evidence.excerpt'])
            ->map(fn ($row): array => ['id' => $row->id, 'text' => (string) $row->excerpt])->all();
        $correlationId = (string) Str::uuid();
        $result = $this->classifier->classify($tenantId, $conversation->id, $messages, $evidence, $correlationId);

        $decision = DB::transaction(function () use ($conversation, $result, $correlationId, $tenantId) {
            $attributes = [
                'intent' => $result['intent'], 'intent_confidence' => $result['confidence'], 'ai_summary' => $result['summary'],
                'recommended_next_action' => $result['recommended_action'], 'recommendation_reason' => $result['reason'],
                'recommendation_evidence' => $result['evidence_references'], 'correlation_id' => $correlationId,
            ];
            if ($result['requires_human']) {
                $attributes += ['status' => ConversationWorkflowStatus::HumanReview,
                    'handoff_reason' => $result['risk'] !== 'none' ? $result['risk'] : 'low_confidence', 'handed_off_at' => now()];
            }
            $conversation->update($attributes);
            if ($result['requires_human'] && $conversation->contact_id) {
                DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('contact_id', $conversation->contact_id)
                    ->whereIn('status', [CampaignEnrollmentStatus::Pending->value, CampaignEnrollmentStatus::Active->value])
                    ->update(['status' => CampaignEnrollmentStatus::HandedOff->value, 'stop_reason' => 'conversation_handoff',
                        'stopped_at' => now(), 'updated_at' => now()]);
            }
            return $this->decisions->record($tenantId, $conversation, $result);
        });

        return ['conversation' => $conversation->fresh(), 'analysis' => $result, 'decision' => $decision, 'correlation_id' => $correlationId];
    }
}
