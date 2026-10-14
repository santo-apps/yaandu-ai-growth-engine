<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadDiscoveryCandidate extends Model
{
    use HasUuids;

    protected $table = 'lead_discovery_candidates';
    protected $guarded = ['id'];
    protected function casts(): array { return ['raw_metadata' => 'array', 'verification_facts' => 'array', 'discovered_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'promoted_at' => 'immutable_datetime']; }
    public function campaign(): BelongsTo { return $this->belongsTo(LeadDiscoveryCampaign::class, 'campaign_id'); }
    public function run(): BelongsTo { return $this->belongsTo(LeadDiscoveryRun::class, 'run_id'); }
    public function evidence(): HasMany { return $this->hasMany(LeadDiscoveryCandidateEvidence::class, 'candidate_id'); }
}
