<?php

namespace App\Models;

use App\Conversations\ConversationWorkflowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'company_id', 'contact_id', 'campaign_id', 'campaign_recipient_id', 'thread_key', 'channel', 'status', 'owner_user_id', 'intent',
        'intent_confidence', 'ai_summary', 'recommended_next_action', 'recommendation_reason', 'recommendation_evidence', 'handoff_reason', 'handed_off_at', 'handed_off_by',
        'correlation_id', 'last_inbound_at', 'misunderstanding_count', 'ownership_state', 'conversation_stage', 'sales_opportunity_id'];

    protected function casts(): array
    {
        return ['status' => ConversationWorkflowStatus::class, 'intent_confidence' => 'float', 'recommendation_evidence' => 'array', 'handed_off_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime', 'misunderstanding_count' => 'integer'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function messages(): HasMany { return $this->hasMany(ConversationMessage::class)->orderBy('created_at'); }
    public function campaignEnrollment(): BelongsTo { return $this->belongsTo(CampaignEnrollment::class, 'campaign_recipient_id'); }
}
