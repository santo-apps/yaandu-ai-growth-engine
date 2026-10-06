<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundMessageEvent extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'outbound_message_id', 'provider', 'provider_event_id', 'event_type', 'occurred_at', 'metadata'];
    protected function casts(): array { return ['occurred_at' => 'immutable_datetime', 'metadata' => 'array']; }
    public function message(): BelongsTo { return $this->belongsTo(OutboundMessage::class, 'outbound_message_id'); }
}
