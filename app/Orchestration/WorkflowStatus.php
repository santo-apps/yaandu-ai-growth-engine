<?php

namespace App\Orchestration;

enum WorkflowStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case WaitingApproval = 'WAITING_APPROVAL';
    case WaitingExternal = 'WAITING_EXTERNAL';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
