<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalVersion extends Model
{
    use HasUuids;
    protected $hidden = ['source_snapshot'];
    protected $fillable = ['tenant_id','proposal_id','version','agent_run_id','prompt_template_id','prompt_version','provider','model','requirements_snapshot','source_snapshot','draft_content','human_edits','requested_scope','recommended_scope','approved_scope','commercial_snapshot','timeline_type','committed_delivery_date','status','created_by','approved_by','approved_at','document_key','document_sha256','document_generated_at','correlation_id','idempotency_key'];
    protected function casts(): array { return ['requirements_snapshot' => 'array','source_snapshot' => 'array','draft_content' => 'array','human_edits' => 'array','requested_scope' => 'array','recommended_scope' => 'array','approved_scope' => 'array','commercial_snapshot' => 'array','approved_at' => 'immutable_datetime','document_generated_at' => 'immutable_datetime','committed_delivery_date' => 'immutable_date']; }
    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
}
