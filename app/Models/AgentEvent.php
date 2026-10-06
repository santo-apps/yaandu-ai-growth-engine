<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentEvent extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'agent_run_id', 'sequence', 'event_key', 'payload'];
    protected function casts(): array { return ['payload' => 'array', 'created_at' => 'datetime']; }
}
