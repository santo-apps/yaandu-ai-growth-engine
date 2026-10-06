<?php

namespace App\Campaigns;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Jobs\ProcessCampaignEnrollment;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Campaign;
use App\Campaigns\SendingWindowCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CampaignLifecycle
{
    public function __construct(
        private readonly OutboundMessagingProviderRouter $providers,
        private readonly SuppressionChecker $suppression,
        private readonly SendingWindowCalculator $windows,
    ) {}

    public function activate(string $tenantId, string $campaignId): Campaign
    {
        try { $this->providers->forTenant($tenantId); }
        catch (RuntimeException $exception) {
            $safeMessage = match ($exception->getMessage()) {
                'Outbound messaging is not enabled for this tenant.',
                'The configured outbound provider is not allowlisted.',
                'The configured outbound provider is unavailable.' => $exception->getMessage(),
                default => 'The configured messaging provider is unavailable.',
            };
            throw new CampaignActivationException($safeMessage, previous: $exception);
        }
        $messaging = DB::table('tenant_messaging_configurations')->where('tenant_id', $tenantId)->where('enabled', true)->first();
        if (! $messaging || ! $messaging->from_email) throw new CampaignActivationException('A sender address is required before activation.');
        $scheduled = [];
        $campaign = DB::transaction(function () use ($tenantId, $campaignId, &$scheduled): Campaign {
            $campaign = Campaign::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);
            if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Paused], true)) {
                throw new CampaignActivationException('Only draft or paused campaigns can be activated.');
            }
            $steps = $campaign->steps()->where('active', true)->with('template')->get();
            if ($steps->isEmpty()) throw new CampaignActivationException('Campaign steps must be present and ordered without gaps.');
            foreach ($steps as $step) {
                if (! $step->template || $step->template->status !== 'approved' || trim((string) $step->template->subject) === '') {
                    throw new CampaignActivationException('Every campaign step requires an approved template with a subject.');
                }
                try {
                    app(CampaignTemplateRenderer::class)->render($step->template->subject, $step->template->body, [
                        'contact_name' => 'Contact', 'contact_first_name' => 'Contact', 'company_name' => 'Company',
                        'website' => 'https://example.test', 'sender_name' => 'Sender',
                    ]);
                } catch (\InvalidArgumentException) {
                    throw new CampaignActivationException('Every campaign step must use supported template placeholders.');
                }
            }

            $wasDraft = $campaign->status === CampaignStatus::Draft;
            $campaign->update(['status' => CampaignStatus::Active, 'started_at' => $campaign->started_at ?? now(), 'paused_at' => null, 'completed_at' => null]);
            $enrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
                ->whereIn('status', $wasDraft ? [CampaignEnrollmentStatus::Pending->value] : [CampaignEnrollmentStatus::Active->value])
                ->lockForUpdate()->get();
            foreach ($enrollments as $enrollment) {
                if ($this->suppression->isMethodSuppressed($tenantId, $enrollment->contact_method_id)) {
                    DB::table('campaign_recipients')->where('id', $enrollment->id)->update([
                        'status' => CampaignEnrollmentStatus::Suppressed->value, 'suppression_outcome' => 'suppressed',
                        'stop_reason' => 'suppressed', 'stopped_at' => now(), 'updated_at' => now(),
                    ]);
                    continue;
                }
                $dueAt = $wasDraft
                    ? $this->windows->nextAllowedTime($campaign, CarbonImmutable::now()->addSeconds($steps->first()->delay_seconds))
                    : $this->windows->nextAllowedTime($campaign, $enrollment->next_step_at ? CarbonImmutable::parse($enrollment->next_step_at) : CarbonImmutable::now());
                DB::table('campaign_recipients')->where('id', $enrollment->id)->update([
                    'status' => CampaignEnrollmentStatus::Active->value,
                    'current_step_ordinal' => $wasDraft ? 0 : $enrollment->current_step_ordinal,
                    'next_step_at' => $dueAt, 'updated_at' => now(),
                ]);
                $scheduled[] = ['id' => $enrollment->id, 'due_at' => $dueAt];
            }

            return $campaign->fresh();
        });

        foreach ($scheduled as $item) {
            ProcessCampaignEnrollment::dispatch($tenantId, $item['id'])->delay($item['due_at'])->afterCommit();
        }

        return $campaign;
    }

    public function pause(string $tenantId, string $campaignId): Campaign
    {
        $campaign = Campaign::where('tenant_id', $tenantId)->findOrFail($campaignId);
        if ($campaign->status !== CampaignStatus::Active) {
            throw new CampaignActivationException('Only active campaigns can be paused.');
        }
        $campaign->update(['status' => CampaignStatus::Paused, 'paused_at' => now()]);

        return $campaign->fresh();
    }

    public function complete(string $tenantId, string $campaignId): Campaign
    {
        return DB::transaction(function () use ($tenantId, $campaignId): Campaign {
            $campaign = Campaign::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->status === CampaignStatus::Completed) return $campaign;
            if (! in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
                throw new CampaignActivationException('Only active or paused campaigns can be completed.');
            }
            $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
            DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
                ->whereIn('status', [CampaignEnrollmentStatus::Active->value, CampaignEnrollmentStatus::Pending->value])
                ->update(['status' => CampaignEnrollmentStatus::Stopped->value, 'stop_reason' => 'campaign_completed', 'stopped_at' => now(), 'next_step_at' => null, 'updated_at' => now()]);
            $this->cancelQueuedMessages($tenantId, $campaignId);

            return $campaign->fresh();
        });
    }

    public function cancel(string $tenantId, string $campaignId): Campaign
    {
        return DB::transaction(function () use ($tenantId, $campaignId): Campaign {
            $campaign = Campaign::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->status === CampaignStatus::Cancelled) return $campaign;
            if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Active, CampaignStatus::Paused], true)) {
                throw new CampaignActivationException('Only draft, active, or paused campaigns can be cancelled.');
            }
            $campaign->update(['status' => CampaignStatus::Cancelled, 'cancelled_at' => now()]);
            DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
                ->whereIn('status', [CampaignEnrollmentStatus::Active->value, CampaignEnrollmentStatus::Pending->value])
                ->update(['status' => CampaignEnrollmentStatus::Stopped->value, 'stop_reason' => 'campaign_cancelled', 'stopped_at' => now(), 'next_step_at' => null, 'updated_at' => now()]);
            $this->cancelQueuedMessages($tenantId, $campaignId);

            return $campaign->fresh();
        });
    }

    private function cancelQueuedMessages(string $tenantId, string $campaignId): void
    {
        app(CampaignMessageStopper::class)->stopCampaign($tenantId, $campaignId, 'Campaign stopped before message delivery.');
    }
}
