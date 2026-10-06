<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'company_id', 'name', 'title', 'public_profile_url', 'source_url', 'observed_at', 'extraction_method', 'confidence'];
    protected function casts(): array { return ['observed_at' => 'immutable_datetime']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function methods(): HasMany { return $this->hasMany(ContactMethod::class); }
}
