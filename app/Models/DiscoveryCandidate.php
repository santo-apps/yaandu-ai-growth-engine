<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryCandidate extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'discovery_run_id', 'company_id', 'company_name', 'original_url', 'normalized_domain', 'country', 'city', 'industry', 'source', 'source_reference', 'discovered_at', 'lifecycle_status', 'verification_state', 'http_status', 'canonical_url', 'page_title', 'meta_description', 'has_mobile_viewport', 'uses_https', 'response_time_ms', 'recommended_service', 'recommendation_evidence', 'deduplication_state', 'deduplication_reason', 'import_state', 'rejection_reason', 'confidence', 'failure_code', 'failure_summary'];
    protected $casts = ['discovered_at' => 'immutable_datetime', 'has_mobile_viewport' => 'boolean', 'uses_https' => 'boolean', 'recommendation_evidence' => 'array'];
    public function run() { return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id'); }
    public function company() { return $this->belongsTo(Company::class); }
}
