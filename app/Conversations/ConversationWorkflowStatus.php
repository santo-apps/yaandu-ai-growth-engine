<?php

namespace App\Conversations;

enum ConversationWorkflowStatus: string
{
    case AiActive = 'ai_active';
    case HumanReview = 'human_review';
    case HumanActive = 'human_active';
    case Resolved = 'resolved';
}
