<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOpportunity extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'company_id', 'contact_id', 'conversation_id', 'owner_user_id', 'stage', 'value', 'currency', 'status', 'qualification', 'qualification_score', 'qualification_level', 'qualified_at', 'closed_at', 'source'];
    protected function casts(): array { return ['qualification' => 'array', 'value' => 'decimal:2']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function proposals(): HasMany { return $this->hasMany(Proposal::class); }
}
