<?php

namespace Tests\Unit;

use App\Orchestration\ConfidencePolicy;
use App\Orchestration\WorkflowStage;
use App\Orchestration\WorkflowTransitionMap;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WorkflowGovernanceTest extends TestCase
{
    public function test_transition_map_allows_declared_journey_and_rejects_backward_transition(): void
    {
        $map = new WorkflowTransitionMap();
        $map->assertAllowed(WorkflowStage::Discovery, WorkflowStage::Proposal);
        $this->expectException(ValidationException::class);
        $map->assertAllowed(WorkflowStage::LeadScoring, WorkflowStage::WebsiteIntelligence);
    }

    public function test_confidence_policy_combines_confidence_evidence_intent_and_qualification(): void
    {
        $policy = new ConfidencePolicy();
        self::assertSame('CONTINUE', $policy->evaluate(.96, 'interested', 'LOW', 2, 2, true, 'RUN_SALES_ANALYSIS')['decision']);
        self::assertSame('APPROVAL_REQUIRED', $policy->evaluate(.96, 'interested', 'LOW', 0, 1, true, 'GENERATE_PROPOSAL')['decision']);
        self::assertSame('HUMAN_REVIEW', $policy->evaluate(.99, 'pricing_request', 'LOW', 2, 2, true, 'RUN_SALES_ANALYSIS')['decision']);
        self::assertSame('STOP', $policy->evaluate(.99, 'not_interested', 'LOW', 2, 2, true, 'GENERATE_FOLLOW_UP')['decision']);
    }
}
