<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebsiteResolutionCandidate extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'resolution_id', 'normalized_domain', 'candidate_url', 'source', 'candidate_type', 'status', 'score', 'confidence_band', 'match_summary', 'reviewed_at', 'reviewed_by'];
    protected $casts = ['match_summary' => 'array', 'reviewed_at' => 'immutable_datetime'];
    public function resolution() { return $this->belongsTo(WebsiteResolution::class, 'resolution_id'); }
    public function evidence() { return $this->hasMany(WebsiteResolutionEvidence::class, 'resolution_candidate_id'); }
}
