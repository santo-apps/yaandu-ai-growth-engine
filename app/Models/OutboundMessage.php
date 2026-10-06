<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'campaign_id', 'campaign_recipient_id', 'campaign_step_id', 'contact_method_id',
        'idempotency_key', 'provider', 'provider_message_id', 'status', 'subject_ciphertext', 'body_ciphertext',
        'recipient_hash', 'attempt_count', 'failure_code', 'safe_error', 'channel', 'correlation_id', 'scheduled_at', 'attempted_at',
        'queued_at', 'accepted_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    protected $hidden = ['subject_ciphertext', 'body_ciphertext', 'recipient_hash'];
    protected function casts(): array { return ['scheduled_at' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime', 'queued_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(CampaignEnrollment::class, 'campaign_recipient_id'); }
}
