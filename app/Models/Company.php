<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'name', 'normalized_domain', 'industry', 'location', 'description', 'source', 'status'];
    public function websites() { return $this->hasMany(CompanyWebsite::class); }
}
