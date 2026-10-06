<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMessagingConfiguration extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'provider', 'enabled', 'from_name', 'from_email', 'reply_to_email', 'secret_reference', 'hourly_limit', 'daily_limit', 'configuration', 'version'];
    protected function casts(): array { return ['enabled' => 'boolean', 'configuration' => 'array']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
