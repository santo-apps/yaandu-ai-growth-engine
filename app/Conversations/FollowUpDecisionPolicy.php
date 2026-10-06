<?php

namespace App\Conversations;

use App\Orchestration\ConfidencePolicy;

final class FollowUpDecisionPolicy
{
    private const HIGH_RISK_INTENTS=['pricing_request','meeting_request','proposal_request','objection','unclear'];

    public function __construct(private readonly ConfidencePolicy $confidence = new ConfidencePolicy()) {}

    public function decide(string $intent,float $confidence,bool $hasReply,bool $deterministicUnsubscribe,
        bool $eligibleForNoReplyDraft,bool $hasDraft,int $campaignStepDelayHours=0):array
    {
        $confidenceThreshold=min(1,max(0,(float)config('sales.follow_up_confidence_threshold',0.85)));
        $risk = in_array($intent, self::HIGH_RISK_INTENTS, true) ? 'HIGH' : 'LOW';
        $shared = $this->confidence->evaluate($confidence, $intent, $risk, 0, 0, false, 'GENERATE_FOLLOW_UP');
        if($deterministicUnsubscribe||$intent==='unsubscribe'||$shared['decision']==='STOP')$action='STOP_SEQUENCE';
        elseif(in_array($shared['decision'],['HUMAN_REVIEW','APPROVAL_REQUIRED'],true))$action='REQUEST_HUMAN_REVIEW';
        elseif(in_array($intent,self::HIGH_RISK_INTENTS,true)||$confidence<$confidenceThreshold)$action='REQUEST_HUMAN_REVIEW';
        elseif(!$hasReply)$action=$eligibleForNoReplyDraft&&$hasDraft?'DRAFT_FOLLOW_UP':'NO_ACTION';
        elseif(in_array($intent,['not_interested','wrong_contact'],true))$action='STOP_SEQUENCE';
        else $action=$hasDraft?'DRAFT_FOLLOW_UP':'REQUEST_HUMAN_REVIEW';

        return ['action'=>$action,'requires_human_review'=>true,
            'recommended_delay_hours'=>$action==='DRAFT_FOLLOW_UP'&&!$hasReply?max(0,$campaignStepDelayHours):0];
    }
}
