<?php

namespace App\Models;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignEnrollment extends Model
{
    use HasUuids;

    protected $table = 'campaign_recipients';
    protected $fillable = ['tenant_id', 'campaign_id', 'company_id', 'contact_id', 'contact_method_id', 'status',
        'suppression_outcome', 'idempotency_key', 'current_step_ordinal', 'next_step_at', 'enrolled_at', 'completed_at', 'stopped_at', 'stop_reason'];
    protected function casts(): array
    {
        return ['status' => CampaignEnrollmentStatus::class, 'next_step_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'stopped_at' => 'immutable_datetime'];
    }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
