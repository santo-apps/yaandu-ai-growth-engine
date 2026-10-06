<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentDecisionController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'string', 'in:pending_approval,approved,rejected,applied,completed']]);

        return AgentDecision::where('tenant_id', app('tenant.id'))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->with(['conversation:id,tenant_id,company_id,contact_id,status', 'company:id,name'])
            ->orderByDesc('created_at')->paginate(30);
    }

    public function show(string $id)
    {
        return AgentDecision::where('tenant_id', app('tenant.id'))->with(['conversation', 'company:id,name'])->findOrFail($id);
    }

    public function approve(Request $request, string $id)
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, string $id, bool $approve): AgentDecision
    {
        $tenantId = app('tenant.id');
        $role = $request->user()->tenants()->whereKey($tenantId)->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Decision approval requires an owner or admin.');
        $decision = DB::transaction(function () use ($request, $tenantId, $id, $approve): AgentDecision {
            $decision = AgentDecision::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            abort_unless($decision->status === 'pending_approval' && $decision->requires_human_approval, 409,
                'Only pending decisions that require human approval can be changed.');
            $decision->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $request->user()->id,
                'approved_at' => $approve ? now() : null, 'rejected_at' => $approve ? null : now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id, 'action' => $approve ? 'agent_decision.approved' : 'agent_decision.rejected',
                'subject_type' => AgentDecision::class, 'subject_id' => $decision->id,
                'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode(['action' => $decision->action]), 'created_at' => now()]);

            return $decision->fresh();
        });

        return $decision;
    }
}
