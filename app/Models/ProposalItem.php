<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalItem extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'proposal_id', 'service_id', 'service_name', 'description', 'quantity', 'unit', 'unit_price', 'line_total', 'tax_amount', 'discount_amount', 'discount_reason', 'discount_approved_by', 'discount_approved_at', 'price_override_reason', 'price_approved_by', 'price_approved_at'];
    protected function casts(): array { return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'tax_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'discount_approved_at' => 'immutable_datetime', 'price_approved_at' => 'immutable_datetime']; }
    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
}
