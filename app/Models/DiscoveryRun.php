<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryRun extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'discovery_search_id', 'agent_run_id', 'requested_by', 'status', 'started_at', 'completed_at', 'counts', 'budget', 'idempotency_key'];
    protected $casts = ['started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'counts' => 'array', 'budget' => 'array'];
    public function search() { return $this->belongsTo(DiscoverySearch::class, 'discovery_search_id'); }
    public function candidates() { return $this->hasMany(DiscoveryCandidate::class); }
}
