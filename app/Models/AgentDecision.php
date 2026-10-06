<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDecision extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'conversation_id', 'company_id', 'action', 'reason', 'confidence', 'evidence_references',
        'context', 'requires_human_approval', 'status', 'idempotency_key', 'approved_by', 'approved_at', 'rejected_at'];
    protected function casts(): array
    {
        return ['confidence' => 'float', 'evidence_references' => 'array', 'context' => 'array', 'requires_human_approval' => 'boolean',
            'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
