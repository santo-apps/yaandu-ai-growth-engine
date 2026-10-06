<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Orchestration\ActionRegistry;
use App\Orchestration\ApprovalService;
use App\Orchestration\PolicyEngine;
use App\Orchestration\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrchestrationController extends Controller
{
    public function __construct(private readonly WorkflowService $workflows, private readonly ApprovalService $approvals, private readonly ActionRegistry $registry, private readonly PolicyEngine $policy) {}

    public function summary(): array
    {
        $tenant = app('tenant.id');
        return ['active_workflows' => DB::table('acquisition_workflows')->where('tenant_id', $tenant)->whereIn('status', ['PENDING','RUNNING','WAITING_APPROVAL','WAITING_EXTERNAL'])->count(),
            'waiting_approval' => DB::table('acquisition_workflows')->where('tenant_id', $tenant)->where('status', 'WAITING_APPROVAL')->count(),
            'approvals_pending' => DB::table('workflow_approvals')->where('tenant_id', $tenant)->where('status', 'PENDING')->count(),
            'human_handoffs' => DB::table('acquisition_workflows')->where('tenant_id', $tenant)->where('current_stage', 'HUMAN_HANDOFF')->whereNotIn('status', ['COMPLETED','CANCELLED'])->count(),
            'failed_or_paused' => DB::table('acquisition_workflows')->where('tenant_id', $tenant)->whereIn('status', ['FAILED','PAUSED'])->count(),
            'ai_runs_today' => DB::table('agent_runs')->where('tenant_id', $tenant)->whereDate('created_at', today())->count()];
    }

    public function index(Request $request): array
    {
        $query = DB::table('acquisition_workflows')->where('tenant_id', app('tenant.id'))->orderByDesc('updated_at');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        return ['data' => $query->paginate(min(100, max(1, (int) $request->input('per_page', 25))))];
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['company_id' => ['nullable','uuid'], 'contact_id' => ['nullable','uuid'], 'campaign_id' => ['nullable','uuid'], 'enrollment_id' => ['nullable','uuid'],
            'conversation_id' => ['nullable','uuid'], 'opportunity_id' => ['nullable','uuid'], 'initial_stage' => ['nullable','string','in:DISCOVERY,WEBSITE_INTELLIGENCE,LEAD_SCORING,OUTREACH_PREPARATION,OUTREACH,REPLY_ANALYSIS,SALES_QUALIFICATION,OPPORTUNITY,MEETING,PROPOSAL,HUMAN_HANDOFF']]);
        return ['data' => $this->workflows->create(app('tenant.id'), $data, (string) $request->user()->id)];
    }

    public function show(string $id): array
    {
        $tenant = app('tenant.id');
        $workflow = DB::table('acquisition_workflows')->where('tenant_id', $tenant)->where('id', $id)->first();
        abort_unless($workflow, 404);
        $events = DB::table('workflow_events')->where('tenant_id', $tenant)->where('workflow_id', $id)->orderBy('created_at')->limit(500)->get();
        $runs = DB::table('agent_runs')->where('tenant_id', $tenant)->whereIn('id', DB::table('workflow_events')->where('tenant_id', $tenant)->where('workflow_id', $id)->whereNotNull('agent_run_id')->select('agent_run_id'))->orderByDesc('created_at')->limit(50)->get(['id','agent_key','status','summary','error_code','correlation_id','created_at','finished_at']);
        $approvals = DB::table('workflow_approvals')->where('tenant_id', $tenant)->where('workflow_id', $id)->orderByDesc('requested_at')->get(['id','action','risk','reason','status','requested_at','expires_at','reviewed_at']);
        $campaign = $workflow->campaign_id ? DB::table('campaigns')->where('tenant_id', $tenant)->where('id', $workflow->campaign_id)->first(['id','name','status']) : null;
        $conversation = $workflow->conversation_id ? DB::table('conversations')->where('tenant_id', $tenant)->where('id', $workflow->conversation_id)->first(['id','status','ownership_state','handoff_reason']) : null;
        $opportunity = $workflow->opportunity_id ? DB::table('sales_opportunities')->where('tenant_id', $tenant)->where('id', $workflow->opportunity_id)->first(['id','stage','status','qualification_score','qualification_level']) : null;
        $meeting = $workflow->conversation_id ? DB::table('meeting_bookings')->where('tenant_id', $tenant)->where('conversation_id', $workflow->conversation_id)->orderByDesc('starts_at')->first(['id','status','starts_at']) : null;
        $proposal = $opportunity ? DB::table('proposals')->where('tenant_id', $tenant)->where('sales_opportunity_id', $opportunity->id)->orderByDesc('updated_at')->first(['id','status','version']) : null;
        $pending = $approvals->firstWhere('status', 'PENDING');
        $latest = $events->last();
        $context = ['campaign' => $campaign, 'conversation' => $conversation, 'opportunity' => $opportunity, 'meeting' => $meeting, 'proposal' => $proposal,
            'waiting_reason' => $pending?->reason ?? ($workflow->status === 'PAUSED' ? ($latest->event ?? 'Manual pause') : null),
            'human_owned' => ($conversation->ownership_state ?? null) === 'HUMAN_ACTIVE' || $workflow->current_stage === 'HUMAN_HANDOFF',
            'pending_approval' => $pending, 'next_permitted_action' => $this->nextAction($workflow, $pending)];
        return ['workflow' => $workflow, 'context' => $context, 'events' => $events, 'agent_runs' => $runs, 'approvals' => $approvals];
    }

    public function control(Request $request, string $id, string $operation): array
    {
        $this->authorizeManager($request);
        if (! in_array($operation, ['pause','resume','cancel','retry'], true)) abort(404);
        return ['data' => $this->workflows->control(app('tenant.id'), $id, $operation, (string) $request->user()->id)];
    }

    public function approvals(Request $request): array
    {
        $query = DB::table('workflow_approvals as a')->join('acquisition_workflows as w', function ($join): void { $join->on('w.id','=','a.workflow_id')->on('w.tenant_id','=','a.tenant_id'); })
            ->leftJoin('companies as c', function ($join): void { $join->on('c.id','=','w.company_id')->on('c.tenant_id','=','w.tenant_id'); })
            ->leftJoin('contacts as ct', function ($join): void { $join->on('ct.id','=','w.contact_id')->on('ct.tenant_id','=','w.tenant_id'); })
            ->leftJoin('agent_runs as ar', function ($join): void { $join->on('ar.id','=','a.agent_run_id')->on('ar.tenant_id','=','a.tenant_id'); })
            ->where('a.tenant_id', app('tenant.id'))->orderByDesc('a.requested_at');
        if (! $request->boolean('include_closed')) $query->where('a.status', 'PENDING');
        $page = $query->paginate(50, ['a.id','a.workflow_id','a.action','a.target_type','a.target_id','a.risk','a.reason','a.status','a.requested_at','a.expires_at',
            'a.agent_run_id','c.id as company_id','c.name as company_name','ct.name as contact_name','w.contact_id','w.opportunity_id','w.current_stage','w.status as workflow_status','ar.agent_key','ar.summary as agent_summary']);
        $tenant = app('tenant.id');
        $page->getCollection()->transform(fn ($row) => $this->approvalContext($tenant, $row));
        return ['data' => $page];
    }

    public function approve(Request $request, string $id, string $decision): array
    {
        $this->authorizeManager($request);
        if (! in_array($decision, ['approve','reject'], true)) abort(404);
        return ['data' => $this->approvals->decide(app('tenant.id'), $id, $decision, (string) $request->user()->id)];
    }

    public function requestApproval(Request $request, string $id): array
    {
        $data = $request->validate(['action' => ['required','string','max:48'], 'reason' => ['required','string','max:500'], 'payload' => ['required','array','max:30'], 'agent_run_id' => ['nullable','uuid']]);
        $key = $request->header('Idempotency-Key');
        if (! $key || strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) abort(422, 'A valid Idempotency-Key header is required.');
        return ['data' => $this->approvals->request(app('tenant.id'), $id, $data['action'], $data['payload'], $data['reason'], $key,
            (string) $request->user()->id, $data['agent_run_id'] ?? null)];
    }

    public function policies(Request $request): array
    {
        $tenant = app('tenant.id');
        DB::table('tenant_automation_settings')->insertOrIgnore(['tenant_id' => $tenant, 'autonomy_mode' => 'ASSISTED', 'created_at' => now(), 'updated_at' => now()]);
        $settings = DB::table('tenant_automation_settings')->where('tenant_id', $tenant)->first();
        $overrides = DB::table('tenant_action_policies')->where('tenant_id', $tenant)->pluck('policy','action');
        $actions = [];
        foreach ($this->registry->all() as $name => $meta) $actions[] = [...$meta, 'action' => $name, 'default_policy' => $meta['default']->value,
            'current_policy' => $this->policy->evaluate($tenant, $name)['policy'], 'tenant_override' => $overrides[$name] ?? null];
        return ['autonomy_mode' => $settings->autonomy_mode, 'daily_ai_call_limit' => $settings->daily_ai_call_limit,
            'daily_token_limit' => $settings->daily_token_limit, 'monthly_estimated_spend_limit' => $settings->monthly_estimated_spend_limit, 'actions' => $actions];
    }

    public function updatePolicies(Request $request): array
    {
        $this->authorizeManager($request);
        $data = $request->validate(['autonomy_mode' => ['required','in:MANUAL,ASSISTED,CONTROLLED'], 'daily_ai_call_limit' => ['nullable','integer','min:1','max:1000000'],
            'daily_token_limit' => ['nullable','integer','min:1','max:1000000000'], 'monthly_estimated_spend_limit' => ['nullable','numeric','min:0.0001','max:999999.9999'],
            'actions' => ['sometimes','array','max:30'], 'actions.*.action' => ['required','string','max:48'], 'actions.*.policy' => ['required','in:AUTO_ALLOWED,APPROVAL_REQUIRED,HUMAN_ONLY,DENIED']]);
        $tenant = app('tenant.id');
        foreach ($data['actions'] ?? [] as $action) {
            try { $this->policy->validateTenantPolicy($action['action'], $action['policy']); }
            catch (\InvalidArgumentException $exception) { throw \Illuminate\Validation\ValidationException::withMessages(['actions' => $exception->getMessage()]); }
        }
        DB::transaction(function () use ($data, $tenant, $request): void {
            DB::table('tenant_automation_settings')->updateOrInsert(['tenant_id' => $tenant], [
                'autonomy_mode' => $data['autonomy_mode'], 'daily_ai_call_limit' => $data['daily_ai_call_limit'] ?? null,
                'daily_token_limit' => $data['daily_token_limit'] ?? null, 'monthly_estimated_spend_limit' => $data['monthly_estimated_spend_limit'] ?? null,
                'updated_at' => now(), 'created_at' => now()]);
            foreach ($data['actions'] ?? [] as $item) {
                DB::table('tenant_action_policies')->updateOrInsert(['tenant_id' => $tenant, 'action' => $item['action']], ['id' => (string) Str::uuid(), 'policy' => $item['policy'], 'updated_at' => now(), 'created_at' => now()]);
            }
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => (string) $request->user()->id,
                'action' => 'automation_policy.updated', 'subject_type' => 'tenant_automation_settings', 'subject_id' => $tenant,
                'metadata' => json_encode(['autonomy_mode' => $data['autonomy_mode'], 'actions_changed' => count($data['actions'] ?? [])]), 'created_at' => now()]);
        });
        return $this->policies($request);
    }

    private function authorizeManager(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status','active')->value('tenant_user.role');
        abort_unless(in_array($role, ['owner','admin'], true), 403, 'This orchestration action requires a tenant owner or admin.');
    }

    private function approvalContext(string $tenant, object $row): object
    {
        $row->confidence = null;
        $row->evidence_references = [];
        $row->qualification = null;
        $row->content_summary = null;
        $target = null;
        if ($row->target_type === 'outbound_message') {
            $target = DB::table('outbound_messages')->where('tenant_id', $tenant)->where('id', $row->target_id)->first(['subject_ciphertext','body_ciphertext','campaign_recipient_id']);
            if ($target) {
                $recipient = DB::table('campaign_recipients')->where('tenant_id', $tenant)->where('id', $target->campaign_recipient_id)->first(['contact_id']);
                if (! $row->contact_name && $recipient) $row->contact_name = DB::table('contacts')->where('tenant_id', $tenant)->where('id', $recipient->contact_id)->value('name');
                try {
                    $subject = $target->subject_ciphertext ? \Illuminate\Support\Facades\Crypt::decryptString($target->subject_ciphertext) : '';
                    $body = $target->body_ciphertext ? \Illuminate\Support\Facades\Crypt::decryptString($target->body_ciphertext) : '';
                    $row->content_summary = mb_substr(trim(($subject ? $subject.' — ' : '').$body), 0, 400);
                } catch (\Throwable) { $row->content_summary = 'Message content is unavailable for safe display.'; }
            }
        } elseif ($row->target_type === 'conversation') {
            $row->confidence = DB::table('sales_analyses')->where('tenant_id', $tenant)->where('conversation_id', $row->target_id)->orderByDesc('created_at')->value('confidence');
            $refs = DB::table('sales_analyses')->where('tenant_id', $tenant)->where('conversation_id', $row->target_id)->orderByDesc('created_at')->value('evidence_references');
            $row->evidence_references = $refs ? (json_decode($refs, true) ?: []) : [];
            $row->content_summary = 'Meeting request for the tenant-scoped conversation.';
        }
        if ($row->opportunity_id) {
            $opportunity = DB::table('sales_opportunities')->where('tenant_id', $tenant)->where('id', $row->opportunity_id)->first(['stage','qualification_score','qualification_level','qualification']);
            if ($opportunity) $row->qualification = ['stage' => $opportunity->stage, 'score' => $opportunity->qualification_score,
                'level' => $opportunity->qualification_level, 'snapshot' => json_decode((string) $opportunity->qualification, true) ?: []];
        }
        return $row;
    }

    private function nextAction(object $workflow, ?object $pending): string
    {
        if ($workflow->status === 'CANCELLED' || $workflow->status === 'COMPLETED') return 'No further automation; workflow is terminal.';
        if ($workflow->current_stage === 'HUMAN_HANDOFF') return 'Human review owns the next action.';
        if ($pending) return 'Review the pending '.$pending->action.' approval.';
        if ($workflow->status === 'WAITING_EXTERNAL') return 'Wait for the external reply or domain event.';
        if ($workflow->status === 'PAUSED') return 'An authorized manager may resume or retry after reviewing the timeline.';
        return 'Continue through the next configured deterministic workflow step.';
    }
}
