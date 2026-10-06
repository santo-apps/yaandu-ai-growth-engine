<?php

namespace App\Scheduling;

use App\Models\Conversation;
use App\Models\MeetingBooking;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SchedulingWorkflow
{
    public function __construct(private readonly SchedulingProviderRouter $providers) {}

    public function createRequest(string $tenantId, Conversation $conversation, string $timezone, ?int $actorId): object
    {
        $config = $this->configuration($tenantId);
        $this->assertTimezone($timezone);
        $id = (string) Str::uuid();
        $now = now();
        DB::table('scheduling_requests')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->id,
            'sales_opportunity_id' => $conversation->sales_opportunity_id, 'contact_id' => $conversation->contact_id,
            'owner_user_id' => $config->default_owner_user_id ?? $conversation->owner_user_id,
            'meeting_type' => 'discovery', 'status' => 'REQUESTED', 'timezone' => $timezone,
            'owner_timezone' => $config->owner_timezone,
            'duration_minutes' => $config->meeting_duration_minutes, 'requested_at' => $now,
            'expires_at' => $now->copy()->addHours(24), 'created_by' => $actorId,
            'correlation_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $request = DB::table('scheduling_requests')->where('id', $id)->first();
        $this->activity($tenantId, $request->sales_opportunity_id, $actorId, 'meeting_requested', $request->correlation_id, ['request_id' => $id]);
        app(\App\Orchestration\WorkflowService::class)->recordConversationEvent($tenantId, $conversation->id, 'meeting_requested',
            'workflow:meeting-requested:'.$id, $actorId ? (string) $actorId : null);
        $this->audit($tenantId, $actorId, 'scheduling.requested', $id, $request->correlation_id);
        return $request;
    }

    public function availability(string $tenantId, string $requestId): array
    {
        $request = $this->request($tenantId, $requestId);
        $this->assertPending($request);
        $config = $this->configuration($tenantId);
        $zone = $request->owner_timezone;
        $now = CarbonImmutable::now($zone)->addMinutes((int) $config->minimum_notice_minutes);
        $limit = $now->addDays((int) $config->maximum_horizon_days);
        $provider = $this->providers->forTenant($tenantId);
        $candidateSlots = $provider->availability($zone, $now->toDateTimeImmutable(), $limit->toDateTimeImmutable(), (int) $request->duration_minutes);
        $valid = [];
        foreach ($candidateSlots as $slot) {
            $start = CarbonImmutable::instance($slot['starts_at'])->setTimezone($zone);
            $end = CarbonImmutable::instance($slot['ends_at'])->setTimezone($zone);
            if ($start < $now || $end > $limit || abs((int) $end->diffInMinutes($start)) !== (int) $request->duration_minutes) continue;
            if (! in_array((int) $start->dayOfWeekIso, json_decode($config->allowed_weekdays, true) ?: [], true)) continue;
            if ($start->format('H:i') < $config->working_hours_start || $end->format('H:i') > $config->working_hours_end) continue;
            if ($this->overlapsTenantMeeting($tenantId, $start, $end, $config)) continue;
            $valid[] = [$start, $end];
            if (count($valid) >= 5) break;
        }
        if (! $valid) return [];
        return DB::transaction(function () use ($tenantId, $request, $valid): array {
            DB::table('scheduling_offered_slots')->where('tenant_id', $tenantId)->where('scheduling_request_id', $request->id)->whereIn('status', ['OFFERED', 'SELECTED'])->update(['status' => 'REPLACED', 'updated_at' => now()]);
            $out = [];
            foreach ($valid as [$start, $end]) {
                $persistedStart = self::timestampWithOffset($start->utc());
                $existing = DB::table('scheduling_offered_slots')->where('tenant_id', $tenantId)->where('scheduling_request_id', $request->id)->where('starts_at', $persistedStart)->first();
                $id = $existing?->id ?? (string) Str::uuid();
                $values = ['starts_at' => $persistedStart, 'ends_at' => self::timestampWithOffset($end->utc()), 'timezone' => $request->timezone, 'expires_at' => $request->expires_at,
                    'status' => 'OFFERED', 'updated_at' => now()];
                if ($existing) DB::table('scheduling_offered_slots')->where('id', $id)->update($values);
                else DB::table('scheduling_offered_slots')->insert(['id' => $id, 'tenant_id' => $tenantId, 'scheduling_request_id' => $request->id, ...$values,
                    'created_at' => now()]);
                $out[] = ['id' => $id, 'starts_at' => $start->setTimezone($request->timezone)->toIso8601String(), 'ends_at' => $end->setTimezone($request->timezone)->toIso8601String(), 'timezone' => $request->timezone,
                    'owner_user_id' => $request->owner_user_id, 'meeting_type' => $request->meeting_type];
            }
            DB::table('scheduling_requests')->where('id', $request->id)->update(['status' => 'AWAITING_SELECTION', 'updated_at' => now()]);
            $this->activity($tenantId, $request->sales_opportunity_id, $request->created_by, 'slots_generated', $request->correlation_id, ['count' => count($out)]);
            $this->audit($tenantId, $request->created_by, 'scheduling.availability_generated', $request->id, $request->correlation_id);
            return $out;
        });
    }

    public function select(string $tenantId, string $requestId, string $slotId): object
    {
        return DB::transaction(function () use ($tenantId, $requestId, $slotId): object {
            $request = $this->request($tenantId, $requestId, true);
            $this->assertPending($request);
            $slot = DB::table('scheduling_offered_slots')->where('tenant_id', $tenantId)->where('scheduling_request_id', $requestId)->where('id', $slotId)->where('status', 'OFFERED')->first();
            abort_unless($slot && now()->lt($slot->expires_at), 422, 'The offered time has expired or is no longer valid.');
            DB::table('scheduling_offered_slots')->where('id', $slotId)->update(['status' => 'SELECTED', 'updated_at' => now()]);
            DB::table('scheduling_requests')->where('id', $requestId)->update(['status' => 'SELECTED', 'updated_at' => now()]);
            $this->activity($tenantId, $request->sales_opportunity_id, $request->created_by, 'slot_selected', $request->correlation_id, ['slot_id' => $slotId]);
            $this->audit($tenantId, $request->created_by, 'scheduling.slot_selected', $requestId, $request->correlation_id);
            return (object) ['id' => $slotId, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'timezone' => $slot->timezone];
        });
    }

    public function book(string $tenantId, string $requestId, string $slotId, int $actorId): MeetingBooking
    {
        $providerFailed = false;
        $booking = DB::transaction(function () use ($tenantId, $requestId, $slotId, $actorId, &$providerFailed): ?MeetingBooking {
            $request = $this->request($tenantId, $requestId, true);
            $existing = MeetingBooking::where('tenant_id', $tenantId)->where('scheduling_request_id', $requestId)->first();
            if ($existing) return $existing;
            $slot = DB::table('scheduling_offered_slots')->where('tenant_id', $tenantId)->where('scheduling_request_id', $requestId)->where('id', $slotId)->lockForUpdate()->first();
            abort_unless($slot && $slot->status === 'SELECTED' && now()->lt($slot->expires_at), 422, 'Select a valid, unexpired offered time before booking.');
            $provider = $this->providers->forTenant($tenantId);
            $start = CarbonImmutable::parse($slot->starts_at)->toDateTimeImmutable();
            $end = CarbonImmutable::parse($slot->ends_at)->toDateTimeImmutable();
            $config = $this->configuration($tenantId);
            $this->assertCurrentPolicy($tenantId, $request, $config, $start, $end);
            $this->assertStillAvailable($provider, $request, $start, $end);
            DB::table('scheduling_requests')->where('id', $requestId)->update(['status' => 'BOOKING', 'updated_at' => now()]);
            try {
                $key = 'scheduling:'.$requestId.':'.$slotId;
                $result = $provider->book($request->owner_timezone, $start, $end, $key);
            } catch (\Throwable $exception) {
                DB::table('scheduling_requests')->where('id', $requestId)->update(['status' => 'FAILED', 'updated_at' => now()]);
                $providerFailed = true;
                return null;
            }
            $conversation = Conversation::where('tenant_id', $tenantId)->findOrFail($request->conversation_id);
            $companyName = $conversation->company()->value('name') ?: 'prospect';
            $safeCompany = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($companyName)) ?? ''), 0, 100);
            $title = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags(str_replace('{{company}}', $safeCompany, $config->meeting_title_template))) ?? ''), 0, 180);
            $description = $config->meeting_description_template === null ? null : mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($config->meeting_description_template)) ?? ''), 0, 2000);
            $booking = MeetingBooking::create(['tenant_id' => $tenantId, 'conversation_id' => $request->conversation_id, 'scheduling_request_id' => $requestId,
                'sales_opportunity_id' => $request->sales_opportunity_id, 'contact_id' => $request->contact_id, 'owner_user_id' => $request->owner_user_id,
                'provider' => $provider->providerKey(), 'provider_booking_id' => $result->providerBookingId, 'status' => 'SCHEDULED', 'timezone' => $request->timezone,
                'starts_at' => self::timestampWithOffset($result->startsAt), 'ends_at' => self::timestampWithOffset($result->endsAt), 'idempotency_key' => $key, 'created_by' => $actorId, 'title' => $title,
                'description' => $description]);
            DB::table('scheduling_requests')->where('id', $requestId)->update(['status' => 'BOOKED', 'updated_at' => now()]);
            DB::table('scheduling_offered_slots')->where('id', $slotId)->update(['status' => 'BOOKED', 'updated_at' => now()]);
            if ($request->sales_opportunity_id) {
                DB::table('sales_opportunities')->where('tenant_id', $tenantId)->where('id', $request->sales_opportunity_id)->whereIn('stage', ['NEW','ENGAGED','DISCOVERY','QUALIFIED'])->update(['stage' => 'MEETING_READY', 'updated_at' => now()]);
                $conversation->update(['conversation_stage' => 'MEETING_READY']);
                $this->activity($tenantId, $request->sales_opportunity_id, $actorId, 'meeting_booked', $request->correlation_id, ['meeting_id' => $booking->id]);
            }
            DB::table('conversation_messages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'conversation_id' => $request->conversation_id,
                'direction' => 'system', 'body' => 'Meeting booked for '.CarbonImmutable::instance($result->startsAt)->setTimezone($request->timezone)->format(DATE_ATOM).' ('.$request->timezone.').', 'delivery_status' => null,
                'provider_metadata' => json_encode(['event' => 'meeting_booked', 'meeting_id' => $booking->id]), 'sent_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($tenantId, $actorId, 'meeting.booked', $booking->id, $request->correlation_id);
            app(\App\Orchestration\WorkflowService::class)->recordConversationEvent($tenantId, $request->conversation_id, 'meeting_booked',
                'workflow:meeting-booked:'.$booking->id, $actorId ? (string) $actorId : null);
            return $booking;
        });
        if ($providerFailed || ! $booking) throw new RuntimeException('The meeting provider could not complete this booking.');
        return $booking;
    }

    private function request(string $tenantId, string $id, bool $lock = false): object
    {
        $query = DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $id);
        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }
    private function configuration(string $tenantId): object
    {
        $config = DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->first();
        if (! $config || ! $config->enabled) throw new RuntimeException('Scheduling is not enabled for this tenant.');
        return $config;
    }
    private function assertPending(object $request): void
    {
        abort_unless(in_array($request->status, ['REQUESTED', 'AWAITING_SELECTION', 'SELECTED', 'FAILED'], true), 409, 'This scheduling request is no longer active.');
        abort_if(now()->greaterThan($request->expires_at), 410, 'This scheduling request has expired.');
    }
    private function assertTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) throw ValidationException::withMessages(['timezone' => 'A valid IANA timezone is required.']);
    }
    private function assertStillAvailable(SchedulingProviderInterface $provider, object $request, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $slots = $provider->availability($request->owner_timezone, $start->modify('-1 minute'), $end->modify('+1 minute'), (int) $request->duration_minutes);
        foreach ($slots as $slot) if ($slot['starts_at']->getTimestamp() === $start->getTimestamp() && $slot['ends_at']->getTimestamp() === $end->getTimestamp()) return;
        DB::table('scheduling_requests')->where('id', $request->id)->update(['status' => 'AWAITING_SELECTION', 'updated_at' => now()]);
        DB::table('scheduling_offered_slots')->where('scheduling_request_id', $request->id)->where('status', 'SELECTED')->update(['status' => 'UNAVAILABLE', 'updated_at' => now()]);
        throw new RuntimeException('The selected meeting time is no longer available. Refresh availability and select another time.');
    }
    private function assertCurrentPolicy(string $tenantId, object $request, object $config, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): void
    {
        $start = CarbonImmutable::instance($startAt)->setTimezone($request->owner_timezone);
        $end = CarbonImmutable::instance($endAt)->setTimezone($request->owner_timezone);
        $now = CarbonImmutable::now($request->owner_timezone);
        $limit = $now->addMinutes((int) $config->minimum_notice_minutes)->addDays((int) $config->maximum_horizon_days);
        abort_unless((int) $request->duration_minutes === (int) $config->meeting_duration_minutes
            && $start >= $now->addMinutes((int) $config->minimum_notice_minutes) && $end <= $limit
            && in_array((int) $start->dayOfWeekIso, json_decode($config->allowed_weekdays, true) ?: [], true)
            && $start->format('H:i') >= $config->working_hours_start && $end->format('H:i') <= $config->working_hours_end
            && ! $this->overlapsTenantMeeting($tenantId, $start, $end, $config), 422, 'The selected time no longer matches current scheduling rules. Refresh availability and select another time.');
    }
    private function overlapsTenantMeeting(string $tenantId, CarbonImmutable $start, CarbonImmutable $end, object $config): bool
    {
        $from = $start->subMinutes((int) $config->buffer_before_minutes); $to = $end->addMinutes((int) $config->buffer_after_minutes);
        return DB::table('meeting_bookings')->where('tenant_id', $tenantId)->where('status', 'SCHEDULED')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
    }
    private function activity(string $tenant, ?string $opportunity, ?int $actor, string $type, string $correlation, array $details): void
    {
        if (! $opportunity) return;
        DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'sales_opportunity_id' => $opportunity, 'actor_user_id' => $actor,
            'activity_type' => $type, 'details' => json_encode($details), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'correlation_id' => $correlation]);
    }
    private function audit(string $tenant, ?int $actor, string $action, string $subject, string $correlation): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $actor, 'action' => $action,
            'subject_type' => 'scheduling', 'subject_id' => $subject, 'metadata' => json_encode(['correlation_id' => $correlation]), 'created_at' => now()]);
    }

    private static function timestampWithOffset(DateTimeInterface $value): string
    {
        // Laravel formats DateTime bindings without an offset. PostgreSQL then
        // interprets UTC wall time in the connection timezone, shifting the instant.
        return $value->format('Y-m-d H:i:s.uP');
    }
}
