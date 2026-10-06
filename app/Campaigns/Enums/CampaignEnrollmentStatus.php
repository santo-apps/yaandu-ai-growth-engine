<?php

namespace App\Campaigns\Enums;

enum CampaignEnrollmentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Replied = 'replied';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Suppressed = 'suppressed';
    case HandedOff = 'handed_off';
}
