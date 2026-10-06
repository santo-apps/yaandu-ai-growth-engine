<?php

namespace App\Campaigns;

use Illuminate\Support\Facades\DB;

final class CampaignMessageStopper
{
    public function stopEnrollment(string $tenantId, string $enrollmentId, string $status, string $reason): void
    {
        $messages = DB::table('outbound_messages')->where('tenant_id', $tenantId)
            ->where('campaign_recipient_id', $enrollmentId)->where('status', 'queued')->lockForUpdate()->get(['id', 'campaign_id', 'campaign_recipient_id', 'correlation_id']);
        $this->stop($tenantId, $messages, $status, $reason);
    }

    public function stopCampaign(string $tenantId, string $campaignId, string $reason): void
    {
        $messages = DB::table('outbound_messages')->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)->where('status', 'queued')->lockForUpdate()->get(['id', 'campaign_id', 'campaign_recipient_id', 'correlation_id']);
        $this->stop($tenantId, $messages, 'cancelled', $reason);
    }

    /** @param iterable<object> $messages */
    private function stop(string $tenantId, iterable $messages, string $status, string $reason): void
    {
        foreach ($messages as $message) {
            DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('id', $message->id)->where('status', 'queued')
                ->update(['status' => $status, 'safe_error' => $reason, 'updated_at' => now()]);
            app(CampaignEventRecorder::class)->record($tenantId, $message->campaign_id,
                $status === 'suppressed' ? 'message_suppressed' : 'message_cancelled',
                'message:'.$message->id.':'.$status, $message->campaign_recipient_id, $message->correlation_id,
                ['outbound_message_id' => $message->id, 'reason' => $reason]);
        }
    }
}
