<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebsiteResolutionAttempt extends Model
{
    use HasUuids;
    protected $table = 'website_resolution_attempts';
    protected $fillable = ['tenant_id', 'resolution_id', 'source', 'attempt_number', 'state', 'failure_code', 'failure_summary', 'metrics', 'started_at', 'finished_at'];
    protected $casts = ['metrics' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
