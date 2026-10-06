<?php

namespace App\Agents;

use App\Models\Conversation;
use App\Models\AgentDecision;
use Illuminate\Support\Facades\DB;

final class AgentDecisionService
{
    public function record(string $tenantId, Conversation $conversation, array $analysis): AgentDecision
    {
        $action = $this->action($analysis);
        $requiresApproval = ! in_array($action, [AutonomousAction::NoAction, AutonomousAction::HandoffToSales], true);
        $idempotencyKey = hash('sha256', json_encode([
            $tenantId, $conversation->id, $analysis['intent'], $analysis['confidence'], $action->value, $analysis['evidence_references'], $analysis['reason'],
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenantId, $conversation, $analysis, $action, $requiresApproval, $idempotencyKey): AgentDecision {
            $existing = AgentDecision::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;

            return AgentDecision::create([
                'tenant_id' => $tenantId, 'conversation_id' => $conversation->id, 'company_id' => $conversation->company_id,
                'action' => $action->value, 'reason' => $analysis['reason'], 'confidence' => $analysis['confidence'],
                'evidence_references' => $analysis['evidence_references'], 'context' => ['intent' => $analysis['intent'], 'risk' => $analysis['risk']],
                'requires_human_approval' => $requiresApproval,
                'status' => match ($action) {
                    AutonomousAction::NoAction => 'completed',
                    AutonomousAction::HandoffToSales => 'applied',
                    default => 'pending_approval',
                },
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    private function action(array $analysis): AutonomousAction
    {
        if ($analysis['requires_human']) return AutonomousAction::HandoffToSales;

        return match ($analysis['intent']) {
            'interested' => AutonomousAction::ScheduleFollowup,
            'not_interested', 'unsubscribe' => AutonomousAction::NoAction,
            'meeting_request' => AutonomousAction::RequestMeeting,
            'proposal_request' => AutonomousAction::CreateProposalDraft,
            default => AutonomousAction::SendMessage,
        };
    }
}
