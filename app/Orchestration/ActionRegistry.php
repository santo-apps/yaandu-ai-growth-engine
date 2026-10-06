<?php

namespace App\Orchestration;

final class ActionRegistry
{
    /** @return array<string, array{risk:string, permission:string, default:ActionPolicy, side_effect:string, idempotent:bool}> */
    public function all(): array
    {
        return [
            'RUN_WEBSITE_ANALYSIS' => $this->entry('LOW', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'RUN_LEAD_SCORING' => $this->entry('LOW', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'GENERATE_MARKETING_DRAFT' => $this->entry('LOW', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'ENROLL_CAMPAIGN' => $this->entry('HIGH', 'campaign.enroll', ActionPolicy::HumanOnly, 'INTERNAL_WRITE'),
            'SEND_OUTREACH' => $this->entry('HIGH', 'outreach.approve', ActionPolicy::ApprovalRequired, 'EXTERNAL_CONSEQUENTIAL'),
            'GENERATE_FOLLOW_UP' => $this->entry('LOW', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'SEND_FOLLOW_UP' => $this->entry('HIGH', 'outreach.approve', ActionPolicy::ApprovalRequired, 'EXTERNAL_CONSEQUENTIAL'),
            'RUN_SALES_ANALYSIS' => $this->entry('MEDIUM', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'CREATE_OPPORTUNITY' => $this->entry('MEDIUM', 'opportunity.manage', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'REQUEST_MEETING' => $this->entry('HIGH', 'meeting.approve', ActionPolicy::ApprovalRequired, 'INTERNAL_WRITE'),
            'BOOK_MEETING' => $this->entry('CRITICAL', 'meeting.book', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
            'GENERATE_PROPOSAL' => $this->entry('MEDIUM', 'workflow.execute', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'APPROVE_PROPOSAL' => $this->entry('CRITICAL', 'proposal.approve', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
            'SEND_PROPOSAL' => $this->entry('CRITICAL', 'proposal.send', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
            'HUMAN_HANDOFF' => $this->entry('LOW', 'handoff.manage', ActionPolicy::AutoAllowed, 'INTERNAL_WRITE'),
            'SET_PRICING' => $this->entry('CRITICAL', 'pricing.manage', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
            'SET_DISCOUNT' => $this->entry('CRITICAL', 'pricing.manage', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
            'CLOSE_DEAL' => $this->entry('CRITICAL', 'opportunity.close', ActionPolicy::HumanOnly, 'EXTERNAL_CONSEQUENTIAL'),
        ];
    }

    public function get(string $action): ?array { return $this->all()[$action] ?? null; }

    private function entry(string $risk, string $permission, ActionPolicy $default, string $sideEffect): array
    {
        return ['risk' => $risk, 'permission' => $permission, 'default' => $default, 'side_effect' => $sideEffect, 'idempotent' => true];
    }
}
