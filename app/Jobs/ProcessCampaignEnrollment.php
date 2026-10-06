<?php

namespace App\Jobs;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Campaigns\SendingWindowCalculator;
use App\Campaigns\CampaignEventRecorder;
use App\Models\CampaignStep;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessCampaignEnrollment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1000;
    public int $uniqueFor = 604800;

    public function __construct(public string $tenantId, public string $enrollmentId)
    {
        $this->onQueue('campaigns');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->enrollmentId; }
    public function retryUntil(): DateTimeInterface { return now()->addDays(7); }

    public function handle(SendingWindowCalculator $windows): void
    {
        $enrollment = CampaignEnrollment::where('tenant_id', $this->tenantId)->find($this->enrollmentId);
        if (! $enrollment || $enrollment->status !== CampaignEnrollmentStatus::Active) return;
        $campaign = Campaign::where('tenant_id', $this->tenantId)->find($enrollment->campaign_id);
        if (! $campaign || $campaign->status !== CampaignStatus::Active) return;
        $dueAt = $enrollment->next_step_at ? CarbonImmutable::instance($enrollment->next_step_at) : CarbonImmutable::now();
        $dueAt = $windows->nextAllowedTime($campaign, $dueAt);
        $step = CampaignStep::where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
            ->where('ordinal', '>', (int) ($enrollment->current_step_ordinal ?? 0))->where('active', true)->orderBy('ordinal')->first();
        if ($step) {
            app(CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'step_scheduled',
                'enrollment:'.$enrollment->id.':step:'.$step->id.':'.$dueAt->timestamp, $enrollment->id, null,
                ['step_id' => $step->id], $dueAt);
        }
        if ($dueAt->isFuture()) {
            $this->release(max(1, min(604800, CarbonImmutable::now()->diffInSeconds($dueAt))));

            return;
        }

        ExecuteCampaignStep::dispatch($this->tenantId, $this->enrollmentId)->afterCommit();
    }
}
