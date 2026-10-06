<?php

namespace App\Models;

use App\Campaigns\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasUuids;

    protected $attributes = ['status' => 'draft', 'timezone' => 'UTC', 'rate_limit_per_hour' => 60, 'daily_limit' => 500];

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'description', 'objective', 'status', 'configuration', 'target_audience',
        'timezone', 'sending_windows', 'rate_limit_per_hour', 'daily_limit', 'paused_at', 'completed_at', 'started_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['status' => CampaignStatus::class, 'configuration' => 'array', 'target_audience' => 'array',
            'sending_windows' => 'array', 'paused_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function audience(): HasOne { return $this->hasOne(CampaignAudience::class); }
    public function steps(): HasMany { return $this->hasMany(CampaignStep::class)->orderBy('ordinal'); }
    public function enrollments(): HasMany { return $this->hasMany(CampaignEnrollment::class, 'campaign_id'); }
    public function templates(): HasMany { return $this->hasMany(CampaignTemplate::class); }
}
