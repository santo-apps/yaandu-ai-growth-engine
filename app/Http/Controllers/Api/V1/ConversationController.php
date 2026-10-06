<?php

namespace App\Http\Controllers\Api\V1;

use App\Conversations\ConversationAnalysisService;
use App\Conversations\ConversationReplyDrafter;
use App\Conversations\ConversationWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\AgentDecision;
use App\Models\ConversationReplyDraft;
use App\Jobs\SendConversationReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'string', 'in:open,ai_active,human_review,human_active,resolved']]);

        return Conversation::where('tenant_id', app('tenant.id'))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->with(['company:id,name,industry,location', 'contact:id,name,title'])
            ->orderByDesc('updated_at')->paginate(30);
    }

    public function show(string $id)
    {
        $conversation = Conversation::where('tenant_id', app('tenant.id'))
            ->with(['company:id,name,industry,location,description', 'contact:id,name,title,source_url'])
            ->findOrFail($id);
        $messages = $conversation->messages()->get()->map(fn ($message): array => [
            'id' => $message->id, 'direction' => $message->direction, 'body' => $message->content(),
            'delivery_status' => $message->delivery_status, 'intent' => $message->intent,
            'intent_confidence' => $message->intent_confidence, 'evidence_references' => $message->evidence_references,
            'sent_at' => $message->sent_at, 'created_at' => $message->created_at,
        ]);
        $score = DB::table('lead_scores')->where('tenant_id', app('tenant.id'))->where('company_id', $conversation->company_id)
            ->orderByDesc('scored_at')->first(['score', 'components', 'rule_version', 'scored_at']);
        $evidence = DB::table('lead_evidence')->join('lead_insights', function ($join): void {
            $join->on('lead_insights.id', '=', 'lead_evidence.lead_insight_id')->on('lead_insights.tenant_id', '=', 'lead_evidence.tenant_id');
        })
            ->where('lead_evidence.tenant_id', app('tenant.id'))->where('lead_insights.tenant_id', app('tenant.id'))->where('lead_insights.company_id', $conversation->company_id)
            ->orderByDesc('lead_evidence.observed_at')->limit(40)->get([
                'lead_evidence.id', 'lead_evidence.evidence_type', 'lead_evidence.source_url', 'lead_evidence.excerpt',
                'lead_evidence.confidence', 'lead_insights.statement',
            ]);
        $meetings = DB::table('meeting_bookings')->where('tenant_id', app('tenant.id'))->where('conversation_id', $conversation->id)
            ->orderByDesc('starts_at')->get(['id', 'status', 'title', 'timezone', 'starts_at', 'ends_at', 'meeting_url', 'owner_user_id', 'created_at']);
        $schedulingRequest = DB::table('scheduling_requests')->where('tenant_id', app('tenant.id'))->where('conversation_id', $conversation->id)
            ->whereIn('status', ['REQUESTED', 'AWAITING_SELECTION', 'SELECTED', 'FAILED'])->latest()->first(['id', 'status', 'timezone', 'duration_minutes', 'expires_at']);
        $decision = AgentDecision::where('tenant_id', app('tenant.id'))->where('conversation_id', $conversation->id)->latest()->first();

        $draft = ConversationReplyDraft::where('tenant_id', app('tenant.id'))->where('conversation_id', $conversation->id)->latest()->first();
        $salesOpportunity = $conversation->sales_opportunity_id ? DB::table('sales_opportunities')->where('tenant_id', app('tenant.id'))->where('id', $conversation->sales_opportunity_id)->first() : null;
        if ($salesOpportunity && is_string($salesOpportunity->qualification)) $salesOpportunity->qualification = json_decode($salesOpportunity->qualification, true) ?: [];
        $salesDraft = DB::table('sales_drafts')->where('tenant_id', app('tenant.id'))->where('conversation_id', $conversation->id)->where('status', 'pending')->latest()->first();
        if ($salesDraft) $salesDraft = ['id' => $salesDraft->id, 'status' => $salesDraft->status, 'body' => \Illuminate\Support\Facades\Crypt::decryptString($salesDraft->body_ciphertext)];
        return response()->json(['conversation' => $conversation, 'messages' => $messages, 'lead_score' => $score, 'evidence' => $evidence, 'meetings' => $meetings, 'scheduling_request' => $schedulingRequest, 'decision' => $decision,
            'reply_draft' => $draft ? ['id' => $draft->id, 'subject' => $draft->subject(), 'body' => $draft->body(), 'status' => $draft->status] : null,
            'sales_opportunity' => $salesOpportunity, 'sales_draft' => $salesDraft]);
    }

    public function draftReply(Request $request, string $id, ConversationReplyDrafter $drafter)
    {
        $tenantId = app('tenant.id');
        $conversation = Conversation::where('tenant_id', $tenantId)->findOrFail($id);
        $role = $request->user()->tenants()->whereKey($tenantId)->value('tenant_user.role');
        abort_unless($conversation->status === ConversationWorkflowStatus::HumanActive
            && ((int) $conversation->owner_user_id === (int) $request->user()->id || in_array($role, ['owner', 'admin'], true)),
            403, 'Take over the conversation before generating a reply.');
        try {
            $draft = $drafter->create($tenantId, $id, $request->user()->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) { throw $exception; }
        catch (\Throwable $exception) {
            Log::warning('Conversation reply draft generation failed.', ['tenant_id' => app('tenant.id'), 'conversation_id' => $id, 'exception' => class_basename($exception)]);
            return response()->json(['message' => 'A safe reply draft could not be created.'], 422);
        }
        return response()->json(['id' => $draft->id, 'subject' => $draft->subject(), 'body' => $draft->body(), 'status' => $draft->status], 201);
    }

    public function approveReply(Request $request, string $id, string $draftId)
    {
        $tenantId = app('tenant.id');
        $role = $request->user()->tenants()->whereKey($tenantId)->value('tenant_user.role');
        $draft = DB::transaction(function () use ($request, $tenantId, $id, $draftId, $role) {
            $conversation = Conversation::where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->findOrFail($id);
            abort_unless($conversation->status === ConversationWorkflowStatus::HumanActive
                && ((int) $conversation->owner_user_id === (int) $request->user()->id || in_array($role, ['owner', 'admin'], true)),
                403, 'Take over the conversation before approving a reply.');
            $draft = ConversationReplyDraft::where('tenant_id', $tenantId)->where('conversation_id', $id)->lockForUpdate()->findOrFail($draftId);
            abort_unless($draft->status === 'draft', 409, 'Only a draft can be approved.');
            $draft->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => $request->user()->id,
                'action' => 'conversation.reply_approved', 'subject_type' => ConversationReplyDraft::class, 'subject_id' => $draft->id,
                'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode([]), 'created_at' => now()]);
            return $draft;
        });
        SendConversationReply::dispatch($tenantId, $draft->id)->afterCommit();
        return response()->json(['id' => $draft->id, 'status' => $draft->status], 202);
    }

    public function updateReplyDraft(Request $request, string $id, string $draftId)
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'], 'body' => ['required', 'string', 'max:5000']]);
        $tenantId = app('tenant.id');
        $conversation = Conversation::where('tenant_id', $tenantId)->findOrFail($id);
        $role = $request->user()->tenants()->whereKey($tenantId)->value('tenant_user.role');
        abort_unless($conversation->status === ConversationWorkflowStatus::HumanActive
            && ((int) $conversation->owner_user_id === (int) $request->user()->id || in_array($role, ['owner', 'admin'], true)),
            403, 'Take over the conversation before editing its reply.');
        $draft = ConversationReplyDraft::where('tenant_id', $tenantId)->where('conversation_id', $id)->findOrFail($draftId);
        abort_unless($draft->status === 'draft', 409, 'Only an unapproved draft can be edited.');
        $draft->update(['subject_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString(trim($data['subject'])),
            'body_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString(trim($data['body']))]);
        return response()->json(['id' => $draft->id, 'subject' => $draft->subject(), 'body' => $draft->body(), 'status' => $draft->status]);
    }

    public function classify(string $id, ConversationAnalysisService $analysis)
    {
        $tenantId = app('tenant.id');
        try {
            return response()->json($analysis->analyze($tenantId, $id));
        } catch (\Throwable $exception) {
            Log::warning('Conversation intent classification failed.', ['tenant_id' => $tenantId,
                'conversation_id' => $id,
                'exception' => class_basename($exception)]);
            if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) throw $exception;
            return response()->json(['message' => 'Conversation analysis is temporarily unavailable.'], 503);
        }
    }

    public function takeover(Request $request, string $id)
    {
        $tenantId = app('tenant.id');
        $conversation = DB::transaction(function () use ($request, $id, $tenantId): Conversation {
            $conversation = Conversation::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            if ($conversation->status === ConversationWorkflowStatus::HumanActive) {
                abort_unless((int) $conversation->owner_user_id === (int) $request->user()->id, 409, 'This conversation is assigned to another user.');

                return $conversation;
            }
            abort_unless(in_array($conversation->status, [ConversationWorkflowStatus::AiActive, ConversationWorkflowStatus::HumanReview], true),
                409, 'Only active conversations can be taken over.');
            $conversation->update(['status' => ConversationWorkflowStatus::HumanActive, 'ownership_state' => 'HUMAN_ACTIVE', 'owner_user_id' => $request->user()->id,
                'handed_off_by' => $request->user()->id, 'handed_off_at' => $conversation->handed_off_at ?? now(),
                'handoff_reason' => $conversation->handoff_reason ?? 'manual_takeover']);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id, 'action' => 'conversation.taken_over',
                'subject_type' => Conversation::class, 'subject_id' => $conversation->id,
                'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode(['reason' => $conversation->handoff_reason]), 'created_at' => now()]);
            if ($conversation->sales_opportunity_id) DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'sales_opportunity_id' => $conversation->sales_opportunity_id, 'actor_user_id' => $request->user()->id, 'activity_type' => 'human_takeover',
                'details' => json_encode([]), 'correlation_id' => $conversation->correlation_id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $conversation->fresh();
        });

        app(\App\Orchestration\WorkflowService::class)->recordConversationEvent($tenantId, $conversation->id, 'human_handoff', 'conversation:'.$conversation->id.':human-handoff:'.($conversation->handed_off_at?->timestamp ?? now()->timestamp), (string) $request->user()->id);

        return response()->json($conversation);
    }

    public function resolve(Request $request, string $id)
    {
        $tenantId = app('tenant.id');
        $conversation = DB::transaction(function () use ($request, $id, $tenantId): Conversation {
            $conversation = Conversation::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $role = $request->user()->tenants()->whereKey($tenantId)->value('tenant_user.role');
            abort_unless($conversation->status === ConversationWorkflowStatus::HumanActive
                && ((int) $conversation->owner_user_id === (int) $request->user()->id || in_array($role, ['owner', 'admin'], true)),
                403, 'Only the assigned user or a tenant manager can resolve this conversation.');
            $conversation->update(['status' => ConversationWorkflowStatus::Resolved, 'ownership_state' => 'RESOLVED']);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id, 'action' => 'conversation.resolved',
                'subject_type' => Conversation::class, 'subject_id' => $conversation->id,
                'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode([]), 'created_at' => now()]);

            return $conversation->fresh();
        });

        app(\App\Orchestration\WorkflowService::class)->recordConversationEvent($tenantId, $conversation->id, 'conversation_resolved', 'conversation:'.$conversation->id.':resolved:'.now()->timestamp, (string) $request->user()->id);

        return response()->json($conversation);
    }
}
