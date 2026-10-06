<?php

namespace App\Messaging;

enum OutboundMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Deferred = 'deferred';
    case SoftBounced = 'soft_bounce';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Unsubscribed = 'unsubscribed';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
