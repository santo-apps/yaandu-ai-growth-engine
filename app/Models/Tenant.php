<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tenant extends Model
{
    use HasUuids;
    protected $fillable = ['name', 'slug', 'status', 'settings'];
    protected function casts(): array { return ['settings' => 'array']; }
    public function users() { return $this->belongsToMany(User::class)->withPivot(['role', 'status'])->withTimestamps(); }
}
