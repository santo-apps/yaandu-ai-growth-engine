<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CompanyWebsite extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'company_id', 'url', 'host', 'canonical_url', 'verification_status', 'source'];
}
