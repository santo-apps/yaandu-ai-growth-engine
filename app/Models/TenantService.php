<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantService extends Model
{
    use HasUuids;
    protected $table = 'tenant_services';
    protected $fillable = ['tenant_id', 'sku', 'canonical_service_key', 'name', 'description', 'unit_price', 'currency', 'active', 'category', 'capabilities', 'standard_deliverables', 'optional_deliverables', 'commercial_model', 'unit', 'effective_from', 'effective_until', 'approved_by'];
    protected function casts(): array { return ['unit_price' => 'decimal:2', 'active' => 'boolean', 'capabilities' => 'array', 'standard_deliverables' => 'array', 'optional_deliverables' => 'array', 'effective_from' => 'immutable_datetime', 'effective_until' => 'immutable_datetime']; }
}
