<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStep extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'campaign_id', 'template_id', 'ordinal', 'step_type', 'delay_seconds', 'configuration', 'active'];
    protected function casts(): array { return ['configuration' => 'array', 'active' => 'boolean']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function template(): BelongsTo { return $this->belongsTo(CampaignTemplate::class, 'template_id'); }
}
