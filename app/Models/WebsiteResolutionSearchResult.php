<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebsiteResolutionSearchResult extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'resolution_id', 'attempt_id', 'resolution_candidate_id', 'source', 'query_hash', 'result_hash',
        'query_text', 'result_url', 'target_url', 'title', 'snippet', 'source_rank', 'source_reference', 'result_type', 'target_type', 'metadata', 'retrieved_at'];
    protected $casts = ['metadata' => 'array', 'retrieved_at' => 'immutable_datetime'];

    public function resolution() { return $this->belongsTo(WebsiteResolution::class, 'resolution_id'); }
    public function candidate() { return $this->belongsTo(WebsiteResolutionCandidate::class, 'resolution_candidate_id'); }
}
