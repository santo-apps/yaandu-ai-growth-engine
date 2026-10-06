<?php

namespace App\Models;

use App\Proposals\ProposalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proposal extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'sales_opportunity_id', 'status', 'version', 'object_key', 'title', 'summary', 'terms',
        'subtotal', 'discount_type', 'discount_value', 'total', 'currency', 'valid_until', 'approved_at', 'approved_by', 'sent_at', 'viewed_at', 'accepted_at', 'rejected_at',
        'latest_version_id', 'safe_generation_error', 'safe_document_error', 'ready_to_send_at', 'generated_by'];
    protected function casts(): array
    {
        return ['status' => ProposalStatus::class, 'subtotal' => 'decimal:2', 'discount_value' => 'decimal:2', 'total' => 'decimal:2',
            'valid_until' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'ready_to_send_at' => 'immutable_datetime'];
    }
    public function opportunity(): BelongsTo { return $this->belongsTo(SalesOpportunity::class, 'sales_opportunity_id'); }
    public function items(): HasMany { return $this->hasMany(ProposalItem::class); }
    public function deliveries(): HasMany { return $this->hasMany(ProposalDelivery::class); }
    public function versions(): HasMany { return $this->hasMany(ProposalVersion::class); }
}
