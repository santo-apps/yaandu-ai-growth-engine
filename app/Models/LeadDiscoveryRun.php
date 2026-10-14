<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadDiscoveryRun extends Model
{
    use HasUuids;

    protected $table = 'lead_discovery_runs';
    protected $guarded = ['id'];
    protected function casts(): array { return ['cost_metrics' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime']; }
    public function campaign(): BelongsTo { return $this->belongsTo(LeadDiscoveryCampaign::class, 'campaign_id'); }
    public function candidates(): HasMany { return $this->hasMany(LeadDiscoveryCandidate::class, 'run_id'); }
}
