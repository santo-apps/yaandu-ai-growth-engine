<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'agent_key', 'status', 'requested_by', 'input_hash', 'output_hash', 'summary', 'error_summary', 'error_code', 'correlation_id'];
    protected $hidden = ['input_ciphertext'];
    public function events() { return $this->hasMany(AgentEvent::class)->where('agent_events.tenant_id', $this->tenant_id)->orderBy('sequence'); }
}
