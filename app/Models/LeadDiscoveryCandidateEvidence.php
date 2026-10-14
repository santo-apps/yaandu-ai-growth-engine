<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadDiscoveryCandidateEvidence extends Model
{
    use HasUuids;

    protected $table = 'lead_discovery_candidate_evidence';
    protected $guarded = ['id'];
    protected function casts(): array { return ['attribution' => 'array', 'collected_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime']; }
    public function candidate(): BelongsTo { return $this->belongsTo(LeadDiscoveryCandidate::class, 'candidate_id'); }
}
