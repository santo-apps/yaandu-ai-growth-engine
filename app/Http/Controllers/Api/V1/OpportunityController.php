<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesOpportunity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpportunityController extends Controller
{
    public function index()
    {
        return SalesOpportunity::where('tenant_id', app('tenant.id'))->with('company:id,name,industry,location')
            ->withCount('proposals')->orderByDesc('updated_at')->paginate(30);
    }

    public function store(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'stage' => ['sometimes', 'in:NEW,ENGAGED,DISCOVERY,QUALIFIED,MEETING_READY,PROPOSAL_READY,CLOSED,NOT_QUALIFIED'],
            'qualification' => ['sometimes', 'array']]);
        $tenantId = app('tenant.id');
        abort_unless(DB::table('companies')->where('tenant_id', $tenantId)->where('id', $data['company_id'])->exists(), 404);
        $opportunity = SalesOpportunity::create(['tenant_id' => $tenantId, 'company_id' => $data['company_id'],
            'owner_user_id' => $request->user()->id, 'stage' => $data['stage'] ?? 'NEW', 'status' => 'open',
            'qualification' => $data['qualification'] ?? []]);

        return response()->json($opportunity->load('company:id,name,industry,location'), 201);
    }

    private function authorizeManager(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Opportunity administration requires an owner or admin.');
    }
}
