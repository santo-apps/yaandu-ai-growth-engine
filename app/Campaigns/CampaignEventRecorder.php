<?php

namespace App\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignEventRecorder
{
    /** @param array<string, scalar|null> $metadata */
    public function record(
        string $tenantId,
        string $campaignId,
        string $type,
        string $idempotencyKey,
        ?string $enrollmentId = null,
        ?string $correlationId = null,
        array $metadata = [],
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        DB::table('campaign_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $enrollmentId,
            'event_type' => $type,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
