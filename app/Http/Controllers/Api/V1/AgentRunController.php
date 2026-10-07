<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Company;
use App\Jobs\RunAgentJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AgentRunController extends Controller
{
    public function index()
    {
        return AgentRun::where('tenant_id', app('tenant.id'))->with('events')->latest()->paginate(25);
    }

    public function show(string $id)
    {
        return AgentRun::where('tenant_id', app('tenant.id'))->with('events')->findOrFail($id);
    }

    public function discovery(Request $request)
    {
        $data = $request->validate(['candidates' => ['required', 'array', 'min:1', 'max:100'], 'candidates.*.name' => ['required', 'string', 'max:255'], 'candidates.*.website' => ['required', 'url:http,https', 'max:2048'], 'candidates.*.industry' => ['nullable', 'string', 'max:150'], 'candidates.*.location' => ['nullable', 'string', 'max:150'], 'candidates.*.source' => ['nullable', 'string', 'max:100']]);
        return $this->dispatch($request, 'DiscoveryAgent', $data);
    }

    public function scoreCompany(Request $request, string $company)
    {
        $company = Company::where('tenant_id', app('tenant.id'))->where('status', '!=', 'discovery_candidate')->findOrFail($company);

        return $this->dispatch($request, 'LeadScoringAgent', ['company_id' => $company->id]);
    }

    private function dispatch(Request $request, string $agent, array $input)
    {
        $id = (string) Str::uuid(); $correlationId = (string) Str::uuid(); $tenantId = app('tenant.id');
        DB::transaction(function () use ($id, $correlationId, $tenantId, $agent, $request, $input): void {
            DB::table('agent_runs')->insert(['id' => $id, 'tenant_id' => $tenantId, 'agent_key' => $agent, 'status' => 'queued',
                'requested_by' => $request->user()->id, 'input_hash' => hash('sha256', json_encode($input)), 'correlation_id' => $correlationId, 'created_at' => now(), 'updated_at' => now()]);
            RunAgentJob::dispatch($tenantId, $agent, $input, (string) $request->user()->id, $id)->afterCommit();
        });
        return response()->json(['id' => $id, 'agent_key' => $agent, 'status' => 'queued', 'correlation_id' => $correlationId], 202);
    }
}
