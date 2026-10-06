<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SuppressionEntry extends Model
{
    use HasUuids;

    protected $table = 'suppression_lists';
    protected $fillable = ['tenant_id', 'identifier_hash', 'identifier_type', 'reason', 'scope', 'source', 'suppressed_at'];
    protected function casts(): array { return ['suppressed_at' => 'immutable_datetime']; }
}
