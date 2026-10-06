<?php

namespace App\Messaging;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\CampaignEventRecorder;
use App\Contacts\ContactMethodValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MessageEventProcessor
{
    public function __construct(private readonly ContactMethodValue $values) {}

    public function process(string $tenantId, string $provider, OutboundProviderEvent $event): bool
    {
        $eventKey = hash('sha256', $provider.':'.$event->eventId);
        if (DB::table('outbound_message_events')->where('tenant_id', $tenantId)->where('provider', $provider)->where('provider_event_id', $event->eventId)->exists()) {
            return false;
        }
        if (abs(now()->diffInSeconds($event->occurredAt, false)) > (int) config('outbound.webhook_clock_skew_seconds', 300)) {
            throw new RuntimeException('Provider event timestamp is outside the accepted replay window.');
        }

        return DB::transaction(function () use ($tenantId, $provider, $event, $eventKey): bool {
            $message = DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('provider', $provider)
                ->where('provider_message_id', $event->providerMessageId)->lockForUpdate()->first();
            if (! $message) throw new RuntimeException('Outbound message was not found for this tenant.');
            $inserted = DB::table('outbound_message_events')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outbound_message_id' => $message->id,
                'provider' => $provider, 'provider_event_id' => $event->eventId, 'event_type' => $event->status->value,
                'occurred_at' => $event->occurredAt, 'metadata' => json_encode(['source' => 'signed_provider_webhook']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) return false;

            $current = OutboundMessageStatus::tryFrom($message->status) ?? OutboundMessageStatus::Unknown;
            if (! $this->canTransition($current, $event->status)) return true;

            DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('id', $message->id)->update([
                'status' => $event->status->value,
                'sent_at' => $event->status === OutboundMessageStatus::Sent ? $event->occurredAt : $message->sent_at,
                'delivered_at' => $event->status === OutboundMessageStatus::Delivered ? $event->occurredAt : $message->delivered_at,
                'updated_at' => now(),
            ]);
            DB::table('conversation_messages')->where('tenant_id', $tenantId)->where('outbound_message_id', $message->id)->update([
                'delivery_status' => $event->status->value,
                'sent_at' => $event->status === OutboundMessageStatus::Sent ? $event->occurredAt : DB::raw('sent_at'),
                'updated_at' => now(),
            ]);
            $enrollmentStatus = match ($event->status) {
                OutboundMessageStatus::Bounced => CampaignEnrollmentStatus::Bounced->value,
                OutboundMessageStatus::Complained => CampaignEnrollmentStatus::Stopped->value,
                OutboundMessageStatus::Unsubscribed => CampaignEnrollmentStatus::Unsubscribed->value,
                OutboundMessageStatus::Failed => CampaignEnrollmentStatus::Stopped->value,
                default => null,
            };
            if ($enrollmentStatus !== null) {
                $reason = match ($event->status) {
                    OutboundMessageStatus::Bounced => 'bounced',
                    OutboundMessageStatus::Complained => 'complaint',
                    OutboundMessageStatus::Unsubscribed => 'unsubscribe',
                    default => 'provider_failed',
                };
                DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $message->campaign_recipient_id)
                    ->whereNotIn('status', [CampaignEnrollmentStatus::Unsubscribed->value])
                    ->update(['status' => $enrollmentStatus, 'stop_reason' => $reason, 'stopped_at' => now(), 'updated_at' => now()]);
                app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($tenantId, $message->campaign_recipient_id,
                    in_array($event->status, [OutboundMessageStatus::Bounced, OutboundMessageStatus::Complained, OutboundMessageStatus::Unsubscribed], true) ? 'suppressed' : 'cancelled',
                    'Provider event stopped this enrollment.');
                $stopReason = match ($event->status) {
                    OutboundMessageStatus::Bounced => 'hard_bounce', OutboundMessageStatus::Complained => 'complaint',
                    OutboundMessageStatus::Unsubscribed => 'unsubscribe', default => 'delivery_failure',
                };
                app(\App\Orchestration\WorkflowService::class)->stopEnrollment($tenantId, $message->campaign_recipient_id, $stopReason,
                    ['outbound_message_id' => $message->id]);

                if (in_array($event->status, [OutboundMessageStatus::Bounced, OutboundMessageStatus::Complained, OutboundMessageStatus::Unsubscribed], true)) {
                    $method = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('id', $message->contact_method_id)->first(['type', 'value']);
                    if ($method && $method->type === 'email') {
                        $hash = $this->values->fingerprint('email', $this->values->decrypt($method->value));
                        DB::table('suppression_lists')->insertOrIgnore([
                            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'identifier_hash' => $hash,
                            'identifier_type' => 'email', 'scope' => 'tenant', 'reason' => $reason,
                            'source' => 'provider_webhook', 'suppressed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('contact_method_id', $message->contact_method_id)
                            ->whereNotIn('status', [CampaignEnrollmentStatus::Unsubscribed->value])
                            ->update(['status' => $enrollmentStatus, 'suppression_outcome' => $reason, 'stop_reason' => $reason, 'stopped_at' => now(), 'updated_at' => now()]);
                        $affectedEnrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)
                            ->where('contact_method_id', $message->contact_method_id)->whereIn('status', [$enrollmentStatus])->get(['id']);
                        foreach ($affectedEnrollments as $affected) {
                            app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($tenantId, $affected->id,
                                'suppressed', 'Recipient address is suppressed.');
                            app(\App\Orchestration\WorkflowService::class)->stopEnrollment($tenantId, $affected->id, $stopReason,
                                ['outbound_message_id' => $message->id]);
                        }
                    }
                }
            }

            $campaignEventType = match ($event->status) {
                OutboundMessageStatus::Sent => 'message_sent',
                OutboundMessageStatus::Delivered => 'message_delivered',
                OutboundMessageStatus::Deferred => 'message_deferred',
                OutboundMessageStatus::SoftBounced => 'message_soft_bounced',
                OutboundMessageStatus::Bounced => 'contact_bounced',
                OutboundMessageStatus::Complained => 'contact_complained',
                OutboundMessageStatus::Unsubscribed => 'contact_unsubscribed',
                default => $event->status->value,
            };
            app(CampaignEventRecorder::class)->record($tenantId, $message->campaign_id, $campaignEventType,
                'outbound:'.$message->id.':'.$campaignEventType, $message->campaign_recipient_id, null,
                ['outbound_message_id' => $message->id, 'provider' => $provider], $event->occurredAt);
            if ($event->status === OutboundMessageStatus::Sent) {
                $companyId = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $message->campaign_recipient_id)->value('company_id');
                if ($companyId) app(\App\Orchestration\WorkflowService::class)->recordCompanyEvent($tenantId, $companyId, 'message_sent',
                    'workflow:message-sent:'.$message->id, ['campaign_id' => $message->campaign_id, 'enrollment_id' => $message->campaign_recipient_id,
                        'outbound_message_id' => $message->id]);
            }
            if ($event->status === OutboundMessageStatus::Delivered) {
                $companyId = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $message->campaign_recipient_id)->value('company_id');
                if ($companyId) app(\App\Orchestration\WorkflowService::class)->recordCompanyEvent($tenantId, $companyId, 'message_delivered',
                    'workflow:message-delivered:'.$message->id, ['campaign_id' => $message->campaign_id, 'enrollment_id' => $message->campaign_recipient_id, 'outbound_message_id' => $message->id]);
            }

            return true;
        });
    }

    private function canTransition(OutboundMessageStatus $current, OutboundMessageStatus $next): bool
    {
        if ($current === $next) return false;
        return match ($current) {
            OutboundMessageStatus::Queued, OutboundMessageStatus::Sending => in_array($next, [OutboundMessageStatus::Accepted, OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered, OutboundMessageStatus::Deferred, OutboundMessageStatus::SoftBounced, OutboundMessageStatus::Bounced, OutboundMessageStatus::Complained, OutboundMessageStatus::Unsubscribed, OutboundMessageStatus::Failed], true),
            OutboundMessageStatus::Accepted, OutboundMessageStatus::Sent, OutboundMessageStatus::Deferred, OutboundMessageStatus::SoftBounced => in_array($next, [OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered, OutboundMessageStatus::Deferred, OutboundMessageStatus::SoftBounced, OutboundMessageStatus::Bounced, OutboundMessageStatus::Complained, OutboundMessageStatus::Unsubscribed, OutboundMessageStatus::Failed], true),
            OutboundMessageStatus::Delivered => in_array($next, [OutboundMessageStatus::Complained, OutboundMessageStatus::Unsubscribed], true),
            default => false,
        };
    }
}
