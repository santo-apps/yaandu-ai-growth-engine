<?php

namespace App\Messaging;

use App\Conversations\ConversationWorkflowStatus;
use App\Campaigns\CampaignEventRecorder;
use App\Contacts\ContactMethodValue;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class InboundMessageProcessor
{
    public function __construct(private readonly ContactMethodValue $values) {}

    public function process(string $provider, InboundMessageEvent $event): ?string
    {
        if (abs(now()->diffInSeconds($event->occurredAt, false)) > (int) config('outbound.webhook_clock_skew_seconds', 300)) {
            throw new RuntimeException('Inbound event timestamp is outside the accepted replay window.');
        }
        $tenantId = $event->tenantId;
        if (DB::table('inbound_message_events')->where('tenant_id', $tenantId)->where('provider', $provider)
            ->where('provider_event_id', $event->eventId)->exists()) return null;
        $fingerprint = $this->values->fingerprint('email', $event->senderEmail);

        return DB::transaction(function () use ($tenantId, $provider, $event, $fingerprint): ?string {
            $duplicate = DB::table('inbound_message_events')->where('tenant_id', $tenantId)->where('provider', $provider)
                ->where('provider_event_id', $event->eventId)->exists();
            if ($duplicate) return null;
            $method = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('type', 'email')->where('value_hash', $fingerprint)->first(['id', 'contact_id']);
            if (! $method) return null;
            $contact = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $method->contact_id)->first(['id', 'company_id']);
            if (! $contact) return null;
            $conversation = Conversation::where('tenant_id', $tenantId)->where('contact_id', $contact->id)->where('channel', 'email')
                ->orderByDesc('updated_at')->first();
            if (! $conversation) {
                $conversation = Conversation::firstOrCreate([
                    'tenant_id' => $tenantId, 'thread_key' => hash('sha256', $contact->id.':email'),
                ], ['company_id' => $contact->company_id, 'contact_id' => $contact->id, 'channel' => 'email',
                    'status' => ConversationWorkflowStatus::AiActive]);
            }

            $messageId = (string) Str::uuid();
            ConversationMessage::create([
                'id' => $messageId, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->id,
                'direction' => 'inbound', 'body' => '', 'body_ciphertext' => Crypt::encryptString($event->body),
                'provider_message_id' => $event->eventId, 'idempotency_key' => 'inbound:'.$provider.':'.$event->eventId,
                'delivery_status' => 'received', 'provider_metadata' => ['provider' => $provider],
                'correlation_id' => (string) Str::uuid(), 'sent_at' => $event->occurredAt,
            ]);
            $attributes = ['last_inbound_at' => $event->occurredAt, 'updated_at' => now()];
            if ($conversation->status === ConversationWorkflowStatus::Resolved) {
                $attributes += ['status' => ConversationWorkflowStatus::HumanReview, 'handoff_reason' => 'new_customer_reply',
                    'handed_off_at' => now()];
            }
            DB::table('conversations')->where('tenant_id', $tenantId)->where('id', $conversation->id)->update($attributes);
            $enrollments = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('contact_id', $contact->id)
                ->whereIn('status', ['pending', 'active', 'completed', 'stopped', 'handed_off'])->lockForUpdate()->get(['id', 'campaign_id']);
            DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('contact_id', $contact->id)
                ->whereIn('status', ['pending', 'active', 'completed', 'stopped', 'handed_off'])->update([
                    'status' => 'replied', 'stop_reason' => 'recipient_replied', 'stopped_at' => now(), 'updated_at' => now(),
                ]);
            foreach ($enrollments as $enrollment) {
                app(CampaignEventRecorder::class)->record($tenantId, $enrollment->campaign_id, 'contact_replied',
                    'reply:'.$provider.':'.$event->eventId.':'.$enrollment->id, $enrollment->id, null,
                    ['conversation_id' => $conversation->id], $event->occurredAt);
                app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($tenantId, $enrollment->id,
                    'cancelled', 'Recipient replied; automated sequence stopped.');
            }
            DB::table('inbound_message_events')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'conversation_id' => $conversation->id,
                'provider' => $provider, 'provider_event_id' => $event->eventId, 'occurred_at' => $event->occurredAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $conversation->id;
        });
    }
}
