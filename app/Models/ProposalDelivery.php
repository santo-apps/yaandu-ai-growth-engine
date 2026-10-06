<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalDelivery extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'proposal_id', 'contact_method_id', 'provider', 'provider_message_id', 'status', 'idempotency_key', 'subject_ciphertext', 'body_ciphertext', 'safe_error', 'sent_at'];
    protected $hidden = ['subject_ciphertext', 'body_ciphertext'];
    protected function casts(): array { return ['sent_at' => 'immutable_datetime']; }
    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
}
