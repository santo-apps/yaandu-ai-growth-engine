<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoverySearch extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'name', 'status', 'criteria', 'source', 'max_candidates', 'created_by'];
    protected $casts = ['criteria' => 'array'];
    public function runs() { return $this->hasMany(DiscoveryRun::class); }
}
