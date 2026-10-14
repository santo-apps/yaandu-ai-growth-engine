<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LeadDiscoverySource extends Model
{
    use HasUuids;

    protected $table = 'lead_discovery_sources';
    protected $guarded = ['id'];
    protected function casts(): array { return ['configuration' => 'array', 'rate_limit_config' => 'array', 'enabled' => 'boolean']; }
}
