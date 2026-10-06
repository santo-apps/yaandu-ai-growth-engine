<?php

namespace App\Orchestration;

use Illuminate\Validation\ValidationException;

final class WorkflowTransitionMap
{
    private const EDGES = [
        'DISCOVERY' => ['WEBSITE_INTELLIGENCE', 'LEAD_SCORING', 'HUMAN_HANDOFF', 'COMPLETE'],
        'WEBSITE_INTELLIGENCE' => ['LEAD_SCORING', 'HUMAN_HANDOFF', 'COMPLETE'],
        'LEAD_SCORING' => ['OUTREACH_PREPARATION', 'SALES_QUALIFICATION', 'HUMAN_HANDOFF', 'COMPLETE'],
        'OUTREACH_PREPARATION' => ['OUTREACH', 'HUMAN_HANDOFF', 'COMPLETE'],
        'OUTREACH' => ['REPLY_ANALYSIS', 'HUMAN_HANDOFF', 'COMPLETE'],
        'REPLY_ANALYSIS' => ['SALES_QUALIFICATION', 'HUMAN_HANDOFF', 'COMPLETE'],
        'SALES_QUALIFICATION' => ['OPPORTUNITY', 'HUMAN_HANDOFF', 'COMPLETE'],
        'OPPORTUNITY' => ['MEETING', 'PROPOSAL', 'HUMAN_HANDOFF', 'COMPLETE'],
        'MEETING' => ['PROPOSAL', 'HUMAN_HANDOFF', 'COMPLETE'],
        'PROPOSAL' => ['HUMAN_HANDOFF', 'COMPLETE'],
        'HUMAN_HANDOFF' => ['HUMAN_HANDOFF'],
        'COMPLETE' => ['COMPLETE'],
    ];

    public function targetForEvent(string $event): ?WorkflowStage
    {
        return match ($event) {
            'company_discovered' => WorkflowStage::Discovery,
            'website_analysis_started', 'website_analysis_completed' => WorkflowStage::WebsiteIntelligence,
            'lead_scored', 'lead_qualified_for_outreach' => WorkflowStage::LeadScoring,
            'marketing_draft_created', 'marketing_approved' => WorkflowStage::OutreachPreparation,
            'campaign_enrolled', 'campaign_started', 'message_queued', 'message_sent', 'message_delivered' => WorkflowStage::Outreach,
            'reply_received', 'reply_analyzed' => WorkflowStage::ReplyAnalysis,
            'sales_analysis_completed' => WorkflowStage::SalesQualification,
            'opportunity_created' => WorkflowStage::Opportunity,
            'meeting_requested', 'meeting_booked' => WorkflowStage::Meeting,
            'proposal_requested', 'proposal_generated', 'proposal_approved' => WorkflowStage::Proposal,
            'human_handoff' => WorkflowStage::HumanHandoff,
            'workflow_completed' => WorkflowStage::Complete,
            default => null,
        };
    }

    public function assertAllowed(WorkflowStage $from, WorkflowStage $to): void
    {
        if ($from === $to || $this->reachable($from->value, $to->value, [])) return;
        throw ValidationException::withMessages(['transition' => "Workflow cannot transition from {$from->value} to {$to->value}."]);
    }

    /** Older business events remain auditable without moving a workflow backwards. */
    public function isBehindCurrentStage(WorkflowStage $current, WorkflowStage $target): bool
    {
        return $current !== $target && $this->reachable($target->value, $current->value, []);
    }

    private function reachable(string $from, string $target, array $seen): bool
    {
        if (in_array($target, self::EDGES[$from] ?? [], true)) return true;
        foreach (self::EDGES[$from] ?? [] as $next) {
            if (isset($seen[$next]) || $next === 'HUMAN_HANDOFF' || $next === 'COMPLETE') continue;
            if ($this->reachable($next, $target, [...$seen, $next => true])) return true;
        }
        return false;
    }
}
