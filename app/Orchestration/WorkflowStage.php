<?php

namespace App\Orchestration;

enum WorkflowStage: string
{
    case Discovery = 'DISCOVERY';
    case WebsiteIntelligence = 'WEBSITE_INTELLIGENCE';
    case LeadScoring = 'LEAD_SCORING';
    case OutreachPreparation = 'OUTREACH_PREPARATION';
    case Outreach = 'OUTREACH';
    case ReplyAnalysis = 'REPLY_ANALYSIS';
    case SalesQualification = 'SALES_QUALIFICATION';
    case Opportunity = 'OPPORTUNITY';
    case Meeting = 'MEETING';
    case Proposal = 'PROPOSAL';
    case HumanHandoff = 'HUMAN_HANDOFF';
    case Complete = 'COMPLETE';
}
