<?php

namespace App\Agents;

enum AutonomousAction: string
{
    case NoAction = 'no_action';
    case SendMessage = 'send_message';
    case ScheduleFollowup = 'schedule_followup';
    case RequestHumanReview = 'request_human_review';
    case HandoffToSales = 'handoff_to_sales';
    case RequestMeeting = 'request_meeting';
    case CreateProposalDraft = 'create_proposal_draft';
}
