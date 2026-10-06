<?php

namespace App\Proposals;

enum ProposalStatus: string
{
    case Draft = 'draft';
    case CommercialInputRequired = 'commercial_input_required';
    case ReviewRequired = 'review_required';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case ReadyToSend = 'ready_to_send';
    case Superseded = 'superseded';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
