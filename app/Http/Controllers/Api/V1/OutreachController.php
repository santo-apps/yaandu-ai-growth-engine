<?php

namespace App\Http\Controllers\Api\V1;

use App\Contacts\ContactMethodValue;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OutreachController extends Controller
{
    public function messages(Request $request)
    {
        $filters = $request->validate([
            'campaign_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(['queued', 'sending', 'accepted', 'sent', 'delivered', 'deferred', 'soft_bounce', 'bounced', 'complained', 'unsubscribed', 'failed', 'suppressed', 'cancelled'])],
        ]);
        $query = DB::table('outbound_messages')->where('tenant_id', app('tenant.id'))
            ->when(isset($filters['campaign_id']), fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('created_at');

        return $query->paginate(30, ['id', 'campaign_id', 'campaign_recipient_id', 'campaign_step_id', 'channel', 'provider',
            'provider_message_id', 'status', 'recipient_hash', 'failure_code', 'safe_error', 'scheduled_at', 'queued_at', 'attempted_at',
            'accepted_at', 'sent_at', 'delivered_at', 'failed_at', 'correlation_id', 'created_at']);
    }

    public function showMessage(string $message)
    {
        $record = DB::table('outbound_messages')->where('tenant_id', app('tenant.id'))->where('id', $message)->first();
        abort_unless($record, 404);
        try {
            $subject = Crypt::decryptString($record->subject_ciphertext);
            $content = Crypt::decryptString($record->body_ciphertext);
        } catch (\Throwable $exception) {
            Log::warning('Stored outbound message could not be decrypted.', [
                'tenant_id' => app('tenant.id'), 'outbound_message_id' => $message, 'exception_type' => class_basename($exception),
            ]);
            return response()->json(['message' => 'Message content is unavailable.'], 422);
        }

        return response()->json([
            'id' => $record->id,
            'campaign_id' => $record->campaign_id,
            'enrollment_id' => $record->campaign_recipient_id,
            'step_id' => $record->campaign_step_id,
            'channel' => 'email',
            'provider' => $record->provider,
            'provider_message_id' => $record->provider_message_id,
            'status' => $record->status,
            'subject' => $subject,
            'content' => $content,
            'failure_code' => $record->failure_code,
            'safe_error' => $record->safe_error,
            'scheduled_at' => $record->scheduled_at,
            'queued_at' => $record->queued_at,
            'attempted_at' => $record->attempted_at,
            'sent_at' => $record->sent_at,
            'delivered_at' => $record->delivered_at,
            'failed_at' => $record->failed_at,
            'correlation_id' => $record->correlation_id,
        ]);
    }

    public function suppressions()
    {
        return DB::table('suppression_lists')->where('tenant_id', app('tenant.id'))
            ->orderByDesc('suppressed_at')->paginate(30, ['id', 'identifier_type', 'identifier_hash', 'reason', 'scope', 'source', 'suppressed_at', 'created_at']);
    }

    public function createSuppression(Request $request, ContactMethodValue $values)
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00-\x1F\x7F]/'],
        ]);
        $tenantId = app('tenant.id');
        $address = mb_strtolower(trim($data['email']));
        $hash = $values->fingerprint('email', $address);

        $suppressionId = DB::transaction(function () use ($tenantId, $hash, $request): string {
            $existing = DB::table('suppression_lists')->where('tenant_id', $tenantId)->where('identifier_hash', $hash)->first();
            if (! $existing) {
                $id = (string) Str::uuid();
                DB::table('suppression_lists')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'identifier_hash' => $hash,
                    'identifier_type' => 'email', 'scope' => 'tenant', 'reason' => 'manual_suppression',
                    'source' => 'admin', 'suppressed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else $id = $existing->id;

            $methodIds = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('type', 'email')->where('value_hash', $hash)->pluck('id');
            $enrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->whereIn('contact_method_id', $methodIds)
                ->whereIn('status', ['pending', 'active'])->lockForUpdate()->get(['id', 'campaign_id']);
            DB::table('campaign_recipients')->where('tenant_id', $tenantId)->whereIn('contact_method_id', $methodIds)
                ->whereIn('status', ['pending', 'active'])->update([
                    'status' => 'suppressed', 'suppression_outcome' => 'manual_suppression', 'stop_reason' => 'manual_suppression',
                    'stopped_at' => now(), 'next_step_at' => null, 'updated_at' => now(),
                ]);
            foreach ($enrollments as $enrollment) {
                app(\App\Campaigns\CampaignEventRecorder::class)->record($tenantId, $enrollment->campaign_id, 'contact_manually_suppressed',
                    'suppression:'.$id.':'.$enrollment->id, $enrollment->id, null, ['reason' => 'manual_suppression']);
                app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($tenantId, $enrollment->id,
                    'suppressed', 'Recipient is suppressed.');
                app(\App\Orchestration\WorkflowService::class)->stopEnrollment($tenantId, $enrollment->id, 'manual_suppression');
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $request->user()->id,
                'action' => 'contact_manually_suppressed', 'subject_type' => 'suppression_list',
                'subject_id' => $id, 'request_id' => substr((string) $request->header('X-Request-ID', ''), 0, 255) ?: null,
                'metadata' => json_encode(['identifier_type' => 'email', 'identifier_hash' => $hash, 'reason' => 'manual_suppression']),
                'created_at' => now(),
            ]);

            return $id;
        });

        return response()->json(['id' => $suppressionId, 'identifier_type' => 'email', 'identifier_hash' => $hash,
            'reason' => DB::table('suppression_lists')->where('tenant_id', $tenantId)->where('id', $suppressionId)->value('reason')], 201);
    }

    private function authorizeManager(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Suppression administration requires an owner or admin role.');
    }
}
