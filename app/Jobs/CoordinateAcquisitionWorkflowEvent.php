<?php

namespace App\Jobs;

use App\Orchestration\AcquisitionWorkflowCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class CoordinateAcquisitionWorkflowEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public string $tenantId, public string $workflowEventId) {}

    public function handle(AcquisitionWorkflowCoordinator $coordinator): void
    {
        $event = DB::table('workflow_events')->where('tenant_id', $this->tenantId)->where('id', $this->workflowEventId)->first();
        if (! $event) return;
        $coordinator->consume($this->tenantId, $event->workflow_id, $event->event,
            json_decode($event->safe_metadata, true) ?: [], $event->actor_user_id);
    }
}
