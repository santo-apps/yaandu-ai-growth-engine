<?php

namespace Tests\Unit;

use App\Agents\FollowUpAgent;
use App\Agents\MarketingAgent;
use App\AI\AIModelRouter;
use App\AI\ApprovedPromptRepository;
use App\Campaigns\SuppressionChecker;
use App\Contacts\ContactMethodValue;
use App\Conversations\FollowUpDecisionPolicy;
use Tests\TestCase;

final class PhaseTwoBAgentContractTest extends TestCase
{
    public function test_agents_expose_only_the_phase_two_b_contract_and_no_tools():void
    {
        $router=new AIModelRouter([],[]);$prompts=new ApprovedPromptRepository();
        $marketingAgent=new MarketingAgent($router,$prompts);$followUpAgent=new FollowUpAgent($router,$prompts,new SuppressionChecker(new ContactMethodValue()),new FollowUpDecisionPolicy());
        self::assertSame('MarketingAgent',$marketingAgent->name());self::assertSame([],$marketingAgent->tools());
        $marketingSchema=$marketingAgent->outputSchema();
        self::assertContains('subject',$marketingSchema['required']);
        self::assertContains('evidence_references',$marketingSchema['required']);
        self::assertSame('FollowUpAgent',$followUpAgent->name());self::assertSame([],$followUpAgent->tools());
        $followup=$followUpAgent->outputSchema();
        self::assertSame(['NO_ACTION','DRAFT_FOLLOW_UP','STOP_SEQUENCE','REQUEST_HUMAN_REVIEW'],$followup['properties']['action']['enum']);
        self::assertNotContains('AUTONOMOUS_SEND',$followup['properties']['action']['enum']);
        self::assertNotContains('NEGOTIATE',$followup['properties']['action']['enum']);
    }

    public function test_confidence_and_risk_policy_uses_only_bounded_recommendation_actions():void
    {
        $policy=new FollowUpDecisionPolicy();
        self::assertSame('REQUEST_HUMAN_REVIEW',$policy->decide('pricing_request',.99,true,false,false,true)['action']);
        self::assertSame('REQUEST_HUMAN_REVIEW',$policy->decide('interested',.6,true,false,false,true)['action']);
        self::assertSame('STOP_SEQUENCE',$policy->decide('interested',.99,true,true,false,true)['action']);
        self::assertSame('DRAFT_FOLLOW_UP',$policy->decide('interested',.9,true,false,false,true)['action']);
        self::assertSame('DRAFT_FOLLOW_UP',$policy->decide('no_reply',.9,false,false,true,true,72)['action']);
        self::assertSame(72,$policy->decide('no_reply',.9,false,false,true,true,72)['recommended_delay_hours']);
        self::assertSame('NO_ACTION',$policy->decide('no_reply',.9,false,false,false,true,72)['action']);
        self::assertSame('NO_ACTION',$policy->decide('no_reply',.9,false,false,true,false,72)['action']);
    }
}
