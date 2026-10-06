<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MeetingBooking;
use App\Scheduling\MeetingLifecycleService;
use App\Scheduling\SchedulingWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchedulingController extends Controller
{
    public function configuration()
    {
        $record = DB::table('tenant_scheduling_configurations')->where('tenant_id', app('tenant.id'))->first([
            'provider', 'enabled', 'default_timezone', 'owner_timezone', 'meeting_duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
            'minimum_notice_minutes', 'maximum_horizon_days', 'allowed_weekdays', 'working_hours_start', 'working_hours_end',
            'meeting_title_template', 'meeting_description_template', 'default_owner_user_id', 'updated_at',
        ]);

        if ($record) $record->allowed_weekdays = json_decode($record->allowed_weekdays, true) ?: [1, 2, 3, 4, 5];
        return response()->json($record ?? ['provider' => 'fake', 'enabled' => false, 'default_timezone' => 'UTC', 'owner_timezone' => 'UTC', 'meeting_duration_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'minimum_notice_minutes' => 60, 'maximum_horizon_days' => 30,
            'allowed_weekdays' => [1, 2, 3, 4, 5], 'working_hours_start' => '09:00', 'working_hours_end' => '17:00', 'default_owner_user_id' => null]);
    }

    public function updateConfiguration(Request $request)
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Scheduling configuration requires an owner or admin.');
        $data = $request->validate([
            'provider' => ['required', Rule::in(config('scheduling.enabled_providers', []))],
            'enabled' => ['required', 'boolean'],
            'default_timezone' => ['required', 'timezone:all'],
            'owner_timezone' => ['required', 'timezone:all'],
            'meeting_duration_minutes' => ['required', 'integer', 'in:30,45,60'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'minimum_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'], 'maximum_horizon_days' => ['required', 'integer', 'min:1', 'max:90'],
            'allowed_weekdays' => ['required', 'array', 'min:1'], 'allowed_weekdays.*' => ['integer', 'between:1,7'],
            'working_hours_start' => ['required', 'date_format:H:i'], 'working_hours_end' => ['required', 'date_format:H:i', 'after:working_hours_start'],
            'meeting_title_template' => ['required', 'string', 'max:180'], 'meeting_description_template' => ['nullable', 'string', 'max:2000'],
            'default_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $tenantId = app('tenant.id');
        abort_if(isset($data['default_owner_user_id']) && ! DB::table('tenant_user')->where('tenant_id', $tenantId)
            ->where('user_id', $data['default_owner_user_id'])->where('status', 'active')->exists(), 422, 'The default meeting owner must be an active member of this tenant.');
        $existing = DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->first(['id']);
        $values = ['provider' => $data['provider'], 'enabled' => $data['enabled'], 'default_timezone' => $data['default_timezone'], 'owner_timezone' => $data['owner_timezone'],
            'meeting_duration_minutes' => $data['meeting_duration_minutes'], 'buffer_before_minutes' => $data['buffer_before_minutes'], 'buffer_after_minutes' => $data['buffer_after_minutes'],
            'minimum_notice_minutes' => $data['minimum_notice_minutes'], 'maximum_horizon_days' => $data['maximum_horizon_days'],
            'allowed_weekdays' => json_encode(array_values(array_unique($data['allowed_weekdays']))), 'working_hours_start' => $data['working_hours_start'],
            'working_hours_end' => $data['working_hours_end'], 'meeting_title_template' => $data['meeting_title_template'],
            'meeting_description_template' => $data['meeting_description_template'] ?? null, 'default_owner_user_id' => $data['default_owner_user_id'] ?? null, 'updated_at' => now()];
        if ($existing) DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->update($values);
        else DB::table('tenant_scheduling_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, ...$values, 'created_at' => now()]);

        return $this->configuration();
    }

    public function createRequest(Request $request, string $conversation, SchedulingWorkflow $workflow)
    {
        $data = $request->validate(['timezone' => ['sometimes', 'timezone:all']]);
        $tenantId = app('tenant.id');
        $record = Conversation::where('tenant_id', $tenantId)->findOrFail($conversation);
        $this->authorizeConversation($request, $tenantId, $record);
        $config = DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->first();
        $timezone = $data['timezone'] ?? $config?->default_timezone ?? 'UTC';
        try { return response()->json($workflow->createRequest($tenantId, $record, $timezone, $request->user()->id), 201); }
        catch (Throwable $exception) { return $this->safeFailure($exception); }
    }

    public function requestAvailability(Request $request, string $schedulingRequest, SchedulingWorkflow $workflow)
    {
        $tenantId = app('tenant.id');
        $record = DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $schedulingRequest)->firstOrFail();
        $this->expireRequestIfNeeded($tenantId, $record);
        $conversation = Conversation::where('tenant_id', $tenantId)->findOrFail($record->conversation_id);
        $this->authorizeConversation($request, $tenantId, $conversation);
        try { return response()->json(['request_id' => $schedulingRequest, 'timezone' => $record->timezone, 'slots' => $workflow->availability($tenantId, $schedulingRequest)]); }
        catch (Throwable $exception) { return $this->safeFailure($exception); }
    }

    public function showRequest(Request $request, string $schedulingRequest)
    {
        $tenant = app('tenant.id');
        $record = DB::table('scheduling_requests')->where('tenant_id', $tenant)->where('id', $schedulingRequest)->firstOrFail();
        $this->expireRequestIfNeeded($tenant, $record);
        $this->authorizeConversation($request, $tenant, Conversation::where('tenant_id', $tenant)->findOrFail($record->conversation_id));
        $record->slots = DB::table('scheduling_offered_slots')->where('tenant_id', $tenant)->where('scheduling_request_id', $record->id)->where('status', '!=', 'REPLACED')->orderBy('starts_at')->get();
        return response()->json($record);
    }

    public function cancelRequest(Request $request, string $schedulingRequest)
    {
        $tenant = app('tenant.id');
        $record = DB::table('scheduling_requests')->where('tenant_id', $tenant)->where('id', $schedulingRequest)->firstOrFail();
        $this->authorizeConversation($request, $tenant, Conversation::where('tenant_id', $tenant)->findOrFail($record->conversation_id));
        abort_unless(in_array($record->status, ['REQUESTED', 'AWAITING_SELECTION', 'SELECTED', 'FAILED'], true), 409, 'This scheduling request cannot be cancelled.');
        DB::transaction(function () use ($tenant, $record, $request): void {
            DB::table('scheduling_requests')->where('tenant_id', $tenant)->where('id', $record->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            DB::table('scheduling_offered_slots')->where('tenant_id', $tenant)->where('scheduling_request_id', $record->id)->whereIn('status', ['OFFERED', 'SELECTED'])->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            if ($record->sales_opportunity_id) DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant,
                'sales_opportunity_id' => $record->sales_opportunity_id, 'actor_user_id' => $request->user()->id, 'activity_type' => 'meeting_request_cancelled',
                'details' => json_encode(['request_id' => $record->id]), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'correlation_id' => $record->correlation_id]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $request->user()->id,
                'action' => 'scheduling.request_cancelled', 'subject_type' => 'scheduling', 'subject_id' => $record->id,
                'metadata' => json_encode(['correlation_id' => $record->correlation_id]), 'created_at' => now()]);
        });
        return response()->json(['id' => $record->id, 'status' => 'CANCELLED']);
    }

    public function selectSlot(Request $request, string $schedulingRequest, SchedulingWorkflow $workflow)
    {
        $data = $request->validate(['slot_id' => ['required', 'uuid']]);
        $tenantId = app('tenant.id');
        $record = DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $schedulingRequest)->firstOrFail();
        $this->expireRequestIfNeeded($tenantId, $record);
        $this->authorizeConversation($request, $tenantId, Conversation::where('tenant_id', $tenantId)->findOrFail($record->conversation_id));
        return response()->json($workflow->select($tenantId, $schedulingRequest, $data['slot_id']));
    }

    public function bookSelected(Request $request, string $schedulingRequest, SchedulingWorkflow $workflow)
    {
        $data = $request->validate(['slot_id' => ['required', 'uuid']]);
        $tenantId = app('tenant.id');
        $record = DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $schedulingRequest)->firstOrFail();
        $this->expireRequestIfNeeded($tenantId, $record);
        $this->authorizeConversation($request, $tenantId, Conversation::where('tenant_id', $tenantId)->findOrFail($record->conversation_id));
        try { return response()->json($this->meetingPayload($workflow->book($tenantId, $schedulingRequest, $data['slot_id'], $request->user()->id)), 201); }
        catch (Throwable $exception) { return $this->safeFailure($exception); }
    }

    private function authorizeConversation(Request $request, string $tenantId, Conversation $conversation): void
    {
        $role = $request->user()->tenants()->whereKey($tenantId)->wherePivot('status', 'active')->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true) || (int) $conversation->owner_user_id === (int) $request->user()->id, 403, 'Scheduling requires an assigned user or tenant manager.');
    }

    private function expireRequestIfNeeded(string $tenantId, object $record): void
    {
        if (in_array($record->status, ['BOOKED', 'CANCELLED', 'EXPIRED'], true)) return;
        if (now()->greaterThan($record->expires_at)) {
            DB::table('scheduling_requests')->where('tenant_id', $tenantId)->where('id', $record->id)->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            abort(410, 'This scheduling request has expired.');
        }
    }

    public function meetings(Request $request)
    {
        $tenant = app('tenant.id');
        $role = $request->user()->tenants()->whereKey($tenant)->wherePivot('status', 'active')->value('tenant_user.role');
        $query = DB::table('meeting_bookings')->where('tenant_id', $tenant);
        if (! in_array($role, ['owner', 'admin'], true)) {
            $query->whereIn('conversation_id', Conversation::where('tenant_id', $tenant)->where('owner_user_id', $request->user()->id)->select('id'));
        }
        return response()->json($query->orderBy('starts_at')->limit(100)->get([
            'id', 'conversation_id', 'sales_opportunity_id', 'contact_id', 'owner_user_id', 'title', 'status', 'timezone', 'starts_at', 'ends_at', 'meeting_url', 'created_at',
        ]));
    }

    public function meeting(Request $request, string $booking)
    {
        $tenant = app('tenant.id');
        $meeting = MeetingBooking::where('tenant_id', $tenant)->findOrFail($booking);
        $this->authorizeConversation($request, $tenant, Conversation::where('tenant_id', $tenant)->findOrFail($meeting->conversation_id));
        return response()->json($this->meetingPayload($meeting));
    }

    public function updateMeetingStatus(Request $request, string $booking)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['COMPLETED', 'NO_SHOW'])]]);
        $tenant = app('tenant.id');
        $record = MeetingBooking::where('tenant_id', $tenant)->findOrFail($booking);
        $conversation = Conversation::where('tenant_id', $tenant)->findOrFail($record->conversation_id);
        $this->authorizeConversation($request, $tenant, $conversation);
        abort_unless($record->status === 'SCHEDULED', 409, 'Only scheduled meetings can be marked completed or no-show.');
        $correlationId = (string) Str::uuid();
        DB::transaction(function () use ($tenant, $record, $data, $request, $correlationId): void {
            $locked = MeetingBooking::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($record->id);
            abort_unless($locked->status === 'SCHEDULED', 409, 'Only scheduled meetings can be marked completed or no-show.');
            $locked->update(['status' => $data['status']]);
            if ($record->sales_opportunity_id) DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant,
                'sales_opportunity_id' => $record->sales_opportunity_id, 'actor_user_id' => $request->user()->id, 'activity_type' => 'meeting_'.strtolower($data['status']),
                'details' => json_encode(['meeting_id' => $record->id, 'correlation_id' => $correlationId]), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'correlation_id' => $correlationId]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $request->user()->id,
                'action' => 'meeting.status_updated', 'subject_type' => MeetingBooking::class, 'subject_id' => $record->id,
                'metadata' => json_encode(['status' => $data['status'], 'correlation_id' => $correlationId]), 'created_at' => now()]);
        });
        return response()->json($this->meetingPayload($record->fresh()));
    }

    public function cancelMeeting(Request $request, string $booking, MeetingLifecycleService $service)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $tenant = app('tenant.id');
        $record = MeetingBooking::where('tenant_id', $tenant)->findOrFail($booking);
        $conversation = Conversation::where('tenant_id', $tenant)->findOrFail($record->conversation_id);
        $this->authorizeConversation($request, $tenant, $conversation);
        try { return response()->json($this->meetingPayload($service->cancel($tenant, $record, 'cancel:'.$record->id, $request->user()->id))); }
        catch (Throwable $exception) { return $this->safeFailure($exception); }
    }

    private function safeFailure(Throwable $exception)
    {
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            return response()->json(['message' => $exception->getMessage() ?: 'The scheduling request could not be completed.'], $status);
        }
        $correlationId = (string) Str::uuid();
        $knownBusinessMessages = [
            'Availability range must be in the future and no longer than 31 days.',
            'Booking requires explicit user confirmation.',
            'Meetings must use an available 30-minute slot.',
            'The requested meeting slot is not available.',
            'This scheduling action requires explicit user confirmation.',
        ];
        $known = in_array($exception->getMessage(), $knownBusinessMessages, true);
        $message = $known ? $exception->getMessage() : 'The scheduling request could not be completed.';
        $code = $known ? 'SCHEDULING_RULE_REJECTED' : 'SCHEDULING_UNAVAILABLE';
        Log::warning('Scheduling request failed.', ['tenant_id' => app('tenant.id'), 'correlation_id' => $correlationId,
            'exception' => class_basename($exception)]);

        return response()->json(['error_code' => $code, 'safe_message' => $message, 'message' => $message,
            'correlation_id' => $correlationId], $known ? 422 : 503);
    }

    private function meetingPayload(MeetingBooking $meeting): array
    {
        return $meeting->only(['id', 'tenant_id', 'conversation_id', 'scheduling_request_id', 'sales_opportunity_id', 'contact_id', 'owner_user_id',
            'title', 'description', 'meeting_url', 'provider', 'status', 'timezone', 'starts_at', 'ends_at', 'cancelled_at', 'created_at', 'updated_at']);
    }
}
