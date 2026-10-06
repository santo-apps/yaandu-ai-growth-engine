<?php

namespace App\Jobs;

use App\Messaging\OutboundMessageRequest;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReplyDraft;
use App\Contacts\ContactMethodValue;
use App\Conversations\ConversationWorkflowStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendConversationReply implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 86400;

    public function __construct(public string $tenantId, public string $draftId) { $this->onQueue('outbound'); }
    public function uniqueId(): string { return $this->tenantId.':conversation-reply:'.$this->draftId; }
    public function backoff(): array { return [20, 120]; }

    public function handle(OutboundMessagingProviderRouter $providers, ContactMethodValue $values): void
    {
        $draft = ConversationReplyDraft::where('tenant_id', $this->tenantId)->where('id', $this->draftId)->first();
        if (! $draft || $draft->status !== 'approved') return;
        $conversation = Conversation::where('tenant_id', $this->tenantId)->whereKey($draft->conversation_id)->first();
        if (! $conversation || $conversation->status !== ConversationWorkflowStatus::HumanActive) {
            $draft->update(['status' => 'failed']);
            return;
        }
        $configuration = DB::table('tenant_messaging_configurations')->where('tenant_id', $this->tenantId)->where('enabled', true)->first();
        if (! $configuration || ! $configuration->from_email) { $draft->update(['status' => 'failed']); return; }
        $method = DB::table('contact_methods')->where('tenant_id', $this->tenantId)->where('contact_id', $conversation->contact_id)
            ->where('type', 'email')->orderByDesc('confidence')->first(['id', 'value', 'verification_status']);
        if (! $method) { $draft->update(['status' => 'failed']); return; }
        $provider = $providers->forTenant($this->tenantId);
        if ($provider->providerKey() !== 'fake' || ! in_array('send', $provider->capabilities(), true)) {
            Log::warning('Conversation reply blocked because only the fake provider is enabled.', ['tenant_id' => $this->tenantId, 'draft_id' => $draft->id]);
            $draft->update(['status' => 'failed']);
            return;
        }
        $result = $provider->send(new OutboundMessageRequest($this->tenantId, $configuration->from_email,
            $values->decrypt($method->value), $draft->subject(), $draft->body(), $configuration->from_name, $configuration->reply_to_email), $draft->idempotency_key);
        if (! in_array($result->status->value, ['accepted', 'sent', 'delivered'], true)) {
            $draft->update(['status' => 'failed']);
            return;
        }
        DB::transaction(function () use ($draft, $conversation, $result): void {
            $locked = ConversationReplyDraft::where('tenant_id', $this->tenantId)->lockForUpdate()->find($draft->id);
            if (! $locked || $locked->status !== 'approved') return;
            $locked->update(['status' => 'sent', 'provider_message_id' => $result->providerMessageId, 'sent_at' => $result->acceptedAt]);
            ConversationMessage::firstOrCreate(['tenant_id' => $this->tenantId, 'idempotency_key' => 'reply:'.$locked->id], [
                'conversation_id' => $conversation->id, 'direction' => 'outbound', 'body' => '',
                'body_ciphertext' => $locked->body_ciphertext, 'provider_message_id' => $result->providerMessageId,
                'delivery_status' => $result->status->value, 'sent_at' => $result->acceptedAt,
                'correlation_id' => $conversation->correlation_id, 'created_by' => $locked->approved_by,
            ]);
        });
    }
}
