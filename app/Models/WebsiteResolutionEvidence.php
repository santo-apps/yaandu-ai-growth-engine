<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebsiteResolutionEvidence extends Model
{
    use HasUuids;
    protected $table = 'website_resolution_evidence';
    protected $fillable = ['tenant_id', 'resolution_id', 'resolution_candidate_id', 'source', 'signal', 'polarity', 'points', 'evidence_key', 'source_reference', 'summary', 'details', 'observed_at'];
    protected $casts = ['details' => 'array', 'observed_at' => 'immutable_datetime'];
}
