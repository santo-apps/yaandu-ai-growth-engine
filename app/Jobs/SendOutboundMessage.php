<?php

namespace App\Jobs;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Campaigns\SendingWindowCalculator;
use App\Campaigns\SuppressionChecker;
use App\Messaging\OutboundMessageRequest;
use App\Messaging\OutboundMessageStatus;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\CampaignStep;
use App\Models\OutboundMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendOutboundMessage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1000;
    public int $maxExceptions = 3;
    public int $timeout = 60;
    public int $uniqueFor = 604800;

    public function __construct(public string $tenantId, public string $messageId)
    {
        $this->onQueue('outbound');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->messageId; }
    public function retryUntil(): DateTimeInterface { return now()->addDays(7); }
    public function backoff(): array { return [30, 300, 1800]; }

    public function handle(
        OutboundMessagingProviderRouter $providers,
        SuppressionChecker $suppression,
        SendingWindowCalculator $windows,
    ): void {
        $message = OutboundMessage::where('tenant_id', $this->tenantId)->find($this->messageId);
        if (! $message || ! in_array($message->status, [OutboundMessageStatus::Queued->value, OutboundMessageStatus::Sending->value], true)) return;

        $campaign = Campaign::where('tenant_id', $this->tenantId)->find($message->campaign_id);
        $enrollment = CampaignEnrollment::where('tenant_id', $this->tenantId)->find($message->campaign_recipient_id);
        if (! $campaign || ! $enrollment || $campaign->status !== CampaignStatus::Active
            || $enrollment->status !== CampaignEnrollmentStatus::Active) return;

        $stepOrdinal = CampaignStep::where('tenant_id', $this->tenantId)->where('id', $message->campaign_step_id)->value('ordinal');
        $requiredApproval = (int) $stepOrdinal > 1 ? 'SEND_FOLLOW_UP' : 'SEND_OUTREACH';
        if (! DB::table('workflow_approvals')->where('tenant_id', $this->tenantId)->where('target_type', 'outbound_message')
            ->where('target_id', $message->id)->where('action', $requiredApproval)->where('status', 'EXECUTED')->exists()) return;

        if (DB::table('conversations')->where('tenant_id', $this->tenantId)->where('contact_id', $enrollment->contact_id)
            ->whereIn('status', ['human_review', 'human_active', 'resolved'])->exists()) {
            $enrollment->update(['status' => CampaignEnrollmentStatus::HandedOff, 'stop_reason' => 'conversation_handoff', 'stopped_at' => now()]);

            return;
        }

        if ($suppression->isMethodSuppressed($this->tenantId, $message->contact_method_id)) {
            $message->update(['status' => OutboundMessageStatus::Suppressed->value, 'safe_error' => 'Recipient is suppressed.']);
            $enrollment->update(['status' => CampaignEnrollmentStatus::Suppressed, 'suppression_outcome' => 'suppressed',
                'stop_reason' => 'suppressed', 'stopped_at' => now(), 'next_step_at' => null]);
            app(\App\Campaigns\CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'message_suppressed',
                'message:'.$message->id.':suppressed', $enrollment->id, $message->correlation_id, ['outbound_message_id' => $message->id]);

            return;
        }

        $now = CarbonImmutable::now();
        $allowedAt = $windows->nextAllowedTime($campaign, $now);
        if ($allowedAt->isFuture()) {
            $this->release(max(1, min(604800, $now->diffInSeconds($allowedAt))));

            return;
        }

        $method = DB::table('contact_methods')->where('tenant_id', $this->tenantId)->where('id', $message->contact_method_id)->first(['type', 'value']);
        if (! $method || $method->type !== 'email') {
            $message->update(['status' => OutboundMessageStatus::Failed->value, 'failure_code' => 'invalid_recipient',
                'safe_error' => 'Email contact method is unavailable.', 'failed_at' => now()]);
            $enrollment->update(['status' => CampaignEnrollmentStatus::Stopped, 'stop_reason' => 'invalid_recipient',
                'stopped_at' => now(), 'next_step_at' => null]);
            app(\App\Campaigns\CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'message_failed',
                'message:'.$message->id.':invalid_recipient', $enrollment->id, $message->correlation_id,
                ['outbound_message_id' => $message->id, 'failure_code' => 'invalid_recipient']);

            return;
        }

        $provider = $providers->forTenant($this->tenantId);
        abort_if(! in_array('send', $provider->capabilities(), true), 422, 'Configured outbound provider cannot send email.');
        $claim = DB::transaction(function () use ($campaign, $message): array|null {
            $configuration = DB::table('tenant_messaging_configurations')->where('tenant_id', $this->tenantId)->where('enabled', true)->lockForUpdate()->first();
            $lockedCampaign = Campaign::where('tenant_id', $this->tenantId)->lockForUpdate()->find($campaign->id);
            $lockedMessage = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->lockForUpdate()->find($message->id);
            if (! $lockedMessage || ! in_array($lockedMessage->status, [OutboundMessageStatus::Queued->value, OutboundMessageStatus::Sending->value], true)
                || ! $lockedCampaign || $lockedCampaign->status !== CampaignStatus::Active) return null;

            if ($lockedMessage->status === OutboundMessageStatus::Sending->value && $lockedMessage->attempted_at
                && CarbonImmutable::parse($lockedMessage->attempted_at)->greaterThan(now()->subSeconds($this->timeout + 15))) {
                return null;
            }
            if (! $configuration || ! $configuration->from_email) {
                DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $message->id)->update([
                    'status' => OutboundMessageStatus::Failed->value, 'failure_code' => 'sender_unavailable',
                    'safe_error' => 'Outbound messaging is not configured for this tenant.', 'failed_at' => now(), 'updated_at' => now(),
                ]);

                return ['terminal' => true];
            }

            $now = now();
            $hourCount = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', '<>', $message->id)
                ->whereNotNull('attempted_at')->where('attempted_at', '>=', $now->copy()->subHour())->count();
            $campaignHourCount = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                ->where('id', '<>', $message->id)->whereNotNull('attempted_at')->where('attempted_at', '>=', $now->copy()->subHour())->count();
            $dayCount = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', '<>', $message->id)
                ->whereNotNull('attempted_at')->where('attempted_at', '>=', $now->copy()->subDay())->count();
            $campaignDayCount = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                ->where('id', '<>', $message->id)->whereNotNull('attempted_at')->where('attempted_at', '>=', $now->copy()->subDay())->count();
            if ($hourCount >= (int) $configuration->hourly_limit || $campaignHourCount >= (int) $lockedCampaign->rate_limit_per_hour) {
                return ['defer' => 60];
            }
            if ($dayCount >= (int) $configuration->daily_limit || $campaignDayCount >= (int) $lockedCampaign->daily_limit) {
                return ['defer' => 3600];
            }

            DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $message->id)->update([
                'status' => OutboundMessageStatus::Sending->value, 'attempt_count' => DB::raw('attempt_count + 1'),
                'attempted_at' => $now, 'updated_at' => $now,
            ]);

            return ['configuration' => $configuration];
        });
        if ($claim === null || ($claim['terminal'] ?? false)) return;
        if (isset($claim['defer'])) { $this->release($claim['defer']); return; }
        $configuration = $claim['configuration'];
        $request = new OutboundMessageRequest(
            tenantId: $this->tenantId,
            senderEmail: $configuration->from_email,
            recipientEmail: Crypt::decryptString($method->value),
            subject: Crypt::decryptString($message->subject_ciphertext),
            body: Crypt::decryptString($message->body_ciphertext),
            senderName: $configuration->from_name,
            replyToEmail: $configuration->reply_to_email,
        );
        $result = $provider->send($request, $message->idempotency_key);

        if (! in_array($result->status, [OutboundMessageStatus::Accepted, OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered], true)) {
            DB::transaction(function () use ($message, $result, $enrollment): void {
                DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $message->id)->update([
                    'status' => $result->status->value, 'provider_message_id' => $result->providerMessageId,
                    'failure_code' => 'provider_rejected', 'safe_error' => 'Outbound provider did not accept the message.',
                    'failed_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('campaign_recipients')->where('tenant_id', $this->tenantId)->where('id', $enrollment->id)
                    ->where('status', CampaignEnrollmentStatus::Active->value)->update([
                        'status' => CampaignEnrollmentStatus::Stopped->value, 'stop_reason' => 'provider_rejected', 'stopped_at' => now(), 'updated_at' => now(),
                    ]);
                app(\App\Campaigns\CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'message_failed',
                    'outbound:'.$message->id.':rejected', $enrollment->id, $message->correlation_id,
                    ['outbound_message_id' => $message->id, 'failure_code' => 'provider_rejected']);
            });

            return;
        }

        DB::transaction(function () use ($message, $result, $enrollment, $campaign): void {
            $sentAt = in_array($result->status, [OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered], true)
                ? $result->acceptedAt : null;
            $deliveredAt = $result->status === OutboundMessageStatus::Delivered ? $result->acceptedAt : null;
            DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $message->id)->update([
                'status' => $result->status->value, 'provider_message_id' => $result->providerMessageId,
                'accepted_at' => $result->acceptedAt, 'sent_at' => $sentAt, 'delivered_at' => $deliveredAt,
                'safe_error' => null, 'updated_at' => now(),
            ]);
            $conversation = Conversation::firstOrCreate([
                'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                'company_id' => $enrollment->company_id, 'contact_id' => $enrollment->contact_id, 'channel' => 'email',
            ], ['status' => 'ai_active', 'campaign_recipient_id' => $enrollment->id]);
            if (! $conversation->campaign_recipient_id) $conversation->update(['campaign_recipient_id' => $enrollment->id]);
            ConversationMessage::firstOrCreate([
                'tenant_id' => $this->tenantId, 'outbound_message_id' => $message->id,
            ], [
                'conversation_id' => $conversation->id, 'direction' => 'outbound', 'body' => '',
                'body_ciphertext' => $message->body_ciphertext, 'delivery_status' => $result->status->value,
                'sent_at' => $sentAt,
                'correlation_id' => $conversation->correlation_id,
            ]);
            $sentOrdinal = (int) DB::table('campaign_steps')->where('tenant_id', $this->tenantId)->where('id', $message->campaign_step_id)->value('ordinal');
            $next = CampaignStep::where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                ->where('ordinal', '>', $sentOrdinal)->where('active', true)->orderBy('ordinal')->first();
            if (! $next) {
                DB::table('campaign_recipients')->where('tenant_id', $this->tenantId)->where('id', $enrollment->id)->update([
                    'status' => CampaignEnrollmentStatus::Completed->value, 'current_step_ordinal' => $sentOrdinal ?: $enrollment->current_step_ordinal,
                    'completed_at' => now(), 'next_step_at' => null, 'updated_at' => now(),
                ]);
            } else {
                $due = now()->addSeconds($next->delay_seconds);
                DB::table('campaign_recipients')->where('tenant_id', $this->tenantId)->where('id', $enrollment->id)->update([
                    'status' => CampaignEnrollmentStatus::Active->value,
                    'current_step_ordinal' => $sentOrdinal,
                    'next_step_at' => $due, 'updated_at' => now(),
                ]);
                ProcessCampaignEnrollment::dispatch($this->tenantId, $enrollment->id)->delay($due)->afterCommit();
            }
            $eventType = match ($result->status) {
                OutboundMessageStatus::Accepted => 'message_accepted',
                OutboundMessageStatus::Sent => 'message_sent',
                OutboundMessageStatus::Delivered => 'message_delivered',
                default => throw new \LogicException('Unexpected successful outbound provider status.'),
            };
            app(\App\Campaigns\CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, $eventType,
                'outbound:'.$message->id.':'.$eventType, $enrollment->id, $message->correlation_id,
                ['outbound_message_id' => $message->id], $result->acceptedAt);
            if (in_array($result->status, [OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered], true)) {
                $workflowEvent = $result->status === OutboundMessageStatus::Sent ? 'message_sent' : 'message_delivered';
                app(\App\Orchestration\WorkflowService::class)->recordCompanyEvent($this->tenantId, $enrollment->company_id, $workflowEvent,
                    'workflow:'.$workflowEvent.':'.$message->id, ['campaign_id' => $campaign->id, 'enrollment_id' => $enrollment->id,
                        'outbound_message_id' => $message->id]);
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $message = OutboundMessage::where('tenant_id', $this->tenantId)->find($this->messageId);
        if (! $message || ! in_array($message->status, [OutboundMessageStatus::Queued->value, OutboundMessageStatus::Sending->value], true)) return;
        DB::transaction(function () use ($message, $exception): void {
            DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $message->id)->update([
                'status' => OutboundMessageStatus::Failed->value, 'failure_code' => 'provider_failure',
                'safe_error' => 'Outbound provider failed after bounded retries.', 'failed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('campaign_recipients')->where('tenant_id', $this->tenantId)->where('id', $message->campaign_recipient_id)
                ->where('status', CampaignEnrollmentStatus::Active->value)->update([
                    'status' => CampaignEnrollmentStatus::Stopped->value, 'stop_reason' => 'provider_failed', 'stopped_at' => now(), 'updated_at' => now(),
                ]);
            app(\App\Campaigns\CampaignEventRecorder::class)->record($this->tenantId, $message->campaign_id, 'message_failed',
                'outbound:'.$message->id.':failed', $message->campaign_recipient_id, $message->correlation_id,
                ['exception_type' => $exception ? class_basename($exception) : 'Unknown']);
        });
        Log::warning('Outbound message failed after retry limit.', ['tenant_id' => $this->tenantId, 'outbound_message_id' => $this->messageId]);
    }
}
