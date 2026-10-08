<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebsiteResolution extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'candidate_id', 'discovery_run_id', 'requested_by', 'state', 'discovery_status', 'discovery_metrics', 'identity_snapshot', 'resolved_candidate_id', 'resolved_domain', 'score', 'confidence_band', 'failure_code', 'failure_summary', 'idempotency_key', 'started_at', 'finished_at'];
    protected $casts = ['identity_snapshot' => 'array', 'discovery_metrics' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    public function candidates() { return $this->hasMany(WebsiteResolutionCandidate::class, 'resolution_id'); }
    public function attempts() { return $this->hasMany(WebsiteResolutionAttempt::class, 'resolution_id'); }
    public function evidence() { return $this->hasMany(WebsiteResolutionEvidence::class, 'resolution_id'); }
    public function searchResults() { return $this->hasMany(WebsiteResolutionSearchResult::class, 'resolution_id'); }
}
