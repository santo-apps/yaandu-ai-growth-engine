<?php

namespace App\Scheduling;

use App\Models\MeetingBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MeetingLifecycleService
{
    public function __construct(private readonly SchedulingProviderRouter $providers) {}

    public function cancel(string $tenantId, MeetingBooking $booking, string $idempotencyKey, ?int $actorId): MeetingBooking
    {
        abort_unless($booking->tenant_id === $tenantId, 404);
        if ($booking->status === 'CANCELLED') return $booking;
        abort_unless($booking->status === 'SCHEDULED', 409, 'Only scheduled meetings can be cancelled.');
        $provider = $this->providers->forExistingMeeting($booking->provider);
        try { $provider->cancel($booking->provider_booking_id, $idempotencyKey); }
        catch (\Throwable) { throw new RuntimeException('The meeting provider could not cancel this meeting.'); }

        $correlationId = $booking->scheduling_request_id ? (DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $booking->scheduling_request_id)->value('correlation_id') ?: (string) Str::uuid()) : (string) Str::uuid();
        return DB::transaction(function () use ($tenantId, $booking, $idempotencyKey, $actorId, $correlationId): MeetingBooking {
            $locked = MeetingBooking::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status === 'CANCELLED') return $locked;
            abort_unless($locked->status === 'SCHEDULED', 409, 'Only scheduled meetings can be cancelled.');
            $locked->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'idempotency_key' => $idempotencyKey]);
            if ($locked->scheduling_request_id) DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $locked->scheduling_request_id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            if ($locked->sales_opportunity_id) DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'sales_opportunity_id' => $locked->sales_opportunity_id, 'actor_user_id' => $actorId, 'activity_type' => 'meeting_cancelled',
                'details' => json_encode(['meeting_id' => $locked->id, 'correlation_id' => $correlationId]), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'correlation_id' => $correlationId]);
            DB::table('conversation_messages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'conversation_id' => $locked->conversation_id,
                'direction' => 'system', 'body' => 'Meeting cancelled by a Yaandu team member.', 'delivery_status' => null,
                'provider_metadata' => json_encode(['event' => 'meeting_cancelled', 'meeting_id' => $locked->id]), 'sent_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $actorId,
                'action' => 'meeting.cancelled', 'subject_type' => MeetingBooking::class, 'subject_id' => $locked->id,
                'metadata' => json_encode(['conversation_id' => $locked->conversation_id, 'correlation_id' => $correlationId]), 'created_at' => now()]);
            return $locked->fresh();
        });
    }
}
