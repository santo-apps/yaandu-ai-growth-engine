<?php

namespace App\Conversations;

enum ConversationIntent: string
{
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case Question = 'question';
    case Objection = 'objection';
    case PricingRequest = 'pricing_request';
    case MeetingRequest = 'meeting_request';
    case ProposalRequest = 'proposal_request';
    case Unsubscribe = 'unsubscribe';
    case WrongContact = 'wrong_contact';
    case Unclear = 'unclear';
}
