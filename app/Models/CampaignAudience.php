<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAudience extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'campaign_id', 'name', 'criteria'];
    protected function casts(): array { return ['criteria' => 'array']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
