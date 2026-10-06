<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'campaign_id', 'name', 'channel', 'subject', 'body', 'status', 'version'];
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
