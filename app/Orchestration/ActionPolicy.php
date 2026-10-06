<?php

namespace App\Orchestration;

enum ActionPolicy: string
{
    case AutoAllowed = 'AUTO_ALLOWED';
    case ApprovalRequired = 'APPROVAL_REQUIRED';
    case HumanOnly = 'HUMAN_ONLY';
    case Denied = 'DENIED';

    public function restrictiveness(): int
    {
        return match ($this) {
            self::AutoAllowed => 0,
            self::ApprovalRequired => 1,
            self::HumanOnly => 2,
            self::Denied => 3,
        };
    }
}
