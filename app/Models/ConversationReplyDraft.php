<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ConversationReplyDraft extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'conversation_id', 'created_by', 'approved_by', 'subject_ciphertext', 'body_ciphertext',
        'evidence_references', 'status', 'idempotency_key', 'provider_message_id', 'approved_at', 'sent_at'];
    protected $hidden = ['subject_ciphertext', 'body_ciphertext'];
    protected function casts(): array { return ['evidence_references' => 'array', 'approved_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime']; }
    public function subject(): string { return Crypt::decryptString($this->subject_ciphertext); }
    public function body(): string { return Crypt::decryptString($this->body_ciphertext); }
}
