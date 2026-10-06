<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ConversationMessage extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'conversation_id', 'outbound_message_id', 'provider_message_id', 'idempotency_key', 'direction', 'body_ciphertext', 'body', 'delivery_status',
        'provider_metadata', 'sent_at', 'intent', 'intent_confidence', 'evidence_references', 'correlation_id', 'created_by'];

    protected $hidden = ['body_ciphertext', 'body', 'provider_metadata'];

    protected function casts(): array
    {
        return ['provider_metadata' => 'array', 'evidence_references' => 'array', 'intent_confidence' => 'float', 'sent_at' => 'immutable_datetime'];
    }

    public function content(): string
    {
        return $this->body_ciphertext ? Crypt::decryptString($this->body_ciphertext) : (string) $this->body;
    }

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
}
