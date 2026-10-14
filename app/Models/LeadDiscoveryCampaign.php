<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadDiscoveryCampaign extends Model
{
    use HasUuids;

    protected $table = 'lead_discovery_campaigns';
    protected $guarded = ['id'];
    protected function casts(): array { return ['configuration' => 'array', 'auto_promotion_enabled' => 'boolean', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime']; }
    public function runs(): HasMany { return $this->hasMany(LeadDiscoveryRun::class, 'campaign_id'); }
    public function candidates(): HasMany { return $this->hasMany(LeadDiscoveryCandidate::class, 'campaign_id'); }
}
