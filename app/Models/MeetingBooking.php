<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingBooking extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'conversation_id', 'scheduling_request_id', 'sales_opportunity_id', 'contact_id', 'owner_user_id', 'title', 'description', 'meeting_url', 'provider', 'provider_booking_id', 'status', 'timezone',
        'starts_at', 'ends_at', 'idempotency_key', 'created_by', 'cancelled_at'];

    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime']; }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
}
