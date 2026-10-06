<?php

namespace App\Http\Controllers\Api\V1;

use App\Contacts\ContactMethodValue;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyWebsite;
use App\Jobs\ScanWebsiteJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        return Company::query()->with('websites')->where('tenant_id', app('tenant.id'))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($request->string('search')->toString()).'%']))
            ->orderBy('name')->paginate(25);
    }

    public function dashboard()
    {
        $tenantId = app('tenant.id');
        $latestScores = DB::table('lead_scores')->where('tenant_id', $tenantId)->select('company_id', DB::raw('max(scored_at) as scored_at'))->groupBy('company_id');

        return response()->json([
            'companies' => DB::table('companies')->where('tenant_id', $tenantId)->count(),
            'completed_scans' => DB::table('website_scans')->where('tenant_id', $tenantId)->where('status', 'completed')->count(),
            'qualified_leads' => DB::table('lead_scores as scores')->joinSub($latestScores, 'latest', function ($join): void {
                $join->on('scores.company_id', '=', 'latest.company_id')->on('scores.scored_at', '=', 'latest.scored_at');
            })->where('scores.tenant_id', $tenantId)->where('scores.score', '>=', 70)->distinct('scores.company_id')->count('scores.company_id'),
            'active_runs' => DB::table('agent_runs')->where('tenant_id', $tenantId)->whereIn('status', ['queued', 'running'])->count(),
            'meetings_requested' => DB::table('scheduling_requests')->where('tenant_id', $tenantId)->count(),
            'meetings_booked' => DB::table('meeting_bookings')->where('tenant_id', $tenantId)->where('status', 'SCHEDULED')->count(),
            'upcoming_meetings' => DB::table('meeting_bookings')->where('tenant_id', $tenantId)->where('status', 'SCHEDULED')->where('starts_at', '>=', now())->count(),
        ]);
    }

    public function contacts(Request $request)
    {
        $tenantId = app('tenant.id');
        $contacts = DB::table('contacts')->join('companies', function ($join) use ($tenantId): void {
            $join->on('companies.id', '=', 'contacts.company_id')->where('companies.tenant_id', '=', $tenantId);
        })->where('contacts.tenant_id', $tenantId)
            ->when($request->filled('company_id'), fn ($query) => $query->where('contacts.company_id', $request->string('company_id')->toString()))
            ->select('contacts.*', 'companies.name as company_name')
            ->orderByDesc('contacts.observed_at')->paginate(25);
        $ids = $contacts->getCollection()->pluck('id')->all();
        $methods = DB::table('contact_methods')->where('tenant_id', $tenantId)->whereIn('contact_id', $ids)->get()->groupBy('contact_id');
        $values = app(ContactMethodValue::class);
        $contacts->getCollection()->transform(function (object $contact) use ($methods, $values): object {
            $contact->methods = $methods->get($contact->id, collect())->map(function (object $method) use ($values): object {
                $method->value = $values->decrypt($method->value);

                return $method;
            })->values();
            return $contact;
        });

        return $contacts;
    }

    public function scores(Request $request)
    {
        return DB::table('lead_scores')->join('companies', function ($join): void {
            $join->on('companies.id', '=', 'lead_scores.company_id')->on('companies.tenant_id', '=', 'lead_scores.tenant_id');
        })->where('lead_scores.tenant_id', app('tenant.id'))
            ->when($request->filled('company_id'), fn ($query) => $query->where('lead_scores.company_id', $request->string('company_id')->toString()))
            ->select('lead_scores.*', 'companies.name as company_name', 'companies.normalized_domain')
            ->orderByDesc('lead_scores.scored_at')->paginate(25);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'website' => ['nullable', 'url:http,https', 'max:2048'], 'industry' => ['nullable', 'string', 'max:150'], 'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        $tenantId = app('tenant.id');
        $domain = isset($data['website']) ? strtolower((string) parse_url($data['website'], PHP_URL_HOST)) : null;
        $company = Company::firstOrCreate(['tenant_id' => $tenantId, 'normalized_domain' => $domain], [
            'id' => (string) Str::uuid(), 'name' => $data['name'], 'industry' => $data['industry'] ?? null,
            'location' => $data['location'] ?? null, 'description' => $data['description'] ?? null, 'source' => 'manual', 'status' => 'new',
        ]);
        if (isset($data['website'])) $company->websites()->firstOrCreate(['tenant_id' => $tenantId, 'host' => $domain], ['id' => (string) Str::uuid(), 'url' => $data['website'], 'source' => 'manual']);
        return response()->json($company->load('websites'), 201);
    }

    public function show(string $company)
    {
        return Company::where('tenant_id', app('tenant.id'))->with(['websites'])->findOrFail($company);
    }

    public function intelligence(string $company)
    {
        $tenantId = app('tenant.id');
        $record = Company::where('tenant_id', $tenantId)->with('websites')->findOrFail($company);
        $scan = DB::table('website_scans as scans')
            ->join('company_websites as websites', 'websites.id', '=', 'scans.company_website_id')
            ->where('scans.tenant_id', $tenantId)
            ->where('websites.tenant_id', $tenantId)
            ->where('websites.company_id', $record->id)
            ->orderByDesc('scans.created_at')
            ->select('scans.*')
            ->first();

        return response()->json([
            'company' => $record,
            'latest_scan' => $scan,
            'pages' => $scan ? DB::table('website_pages')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->orderBy('depth')->orderBy('requested_url')->limit(100)->get(['id', 'requested_url', 'final_url', 'canonical_url', 'http_status', 'content_type', 'title', 'fetched_at', 'depth', DB::raw('substr(extracted_text, 1, 2000) as extracted_text')]) : [],
            'issues' => $scan ? DB::table('website_issues')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->get() : [],
            'technologies' => $scan ? DB::table('website_technologies')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->orderBy('name')->get() : [],
            'screenshots' => $scan ? DB::table('website_screenshots')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->orderByDesc('captured_at')->get(['id', 'viewport', 'captured_at', 'status', 'content_hash']) : [],
            'insights' => DB::table('lead_insights')->where('tenant_id', $tenantId)->where('company_id', $record->id)->latest()->limit(50)->get(),
            'scores' => DB::table('lead_scores')->where('tenant_id', $tenantId)->where('company_id', $record->id)->latest('scored_at')->limit(10)->get(),
        ]);
    }

    public function screenshotContent(string $screenshot)
    {
        $record = DB::table('website_screenshots')->where('tenant_id', app('tenant.id'))->where('id', $screenshot)->where('status', 'stored')->first(['object_key']);
        abort_unless($record, 404);

        return Storage::disk(config('filesystems.default'))->response($record->object_key, null, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function scan(Request $request, string $website)
    {
        $site = CompanyWebsite::where('tenant_id', app('tenant.id'))->findOrFail($website);
        $data = $request->validate(['max_pages' => ['sometimes', 'integer', 'min:1', 'max:100'], 'max_depth' => ['sometimes', 'integer', 'min:0', 'max:5']]);
        $scanId = (string) Str::uuid(); $runId = (string) Str::uuid(); $correlationId = (string) Str::uuid(); $tenantId = app('tenant.id');
        DB::transaction(function () use ($scanId, $runId, $correlationId, $tenantId, $site, $data, $request): void {
            DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenantId, 'company_website_id' => $site->id,
                'status' => 'queued', 'max_depth' => $data['max_depth'] ?? 2, 'max_pages' => $data['max_pages'] ?? 30,
                'crawler_version' => 'http-v1', 'policy_snapshot' => json_encode(['respect_robots' => true]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'queued',
                'requested_by' => $request->user()->id, 'input_hash' => hash('sha256', $scanId), 'correlation_id' => $correlationId, 'created_at' => now(), 'updated_at' => now()]);
            ScanWebsiteJob::dispatch($tenantId, $site->id, $scanId, $runId, (string) $request->user()->id)->afterCommit();
        });
        return response()->json(['scan_id' => $scanId, 'agent_run_id' => $runId, 'status' => 'queued'], 202);
    }
}
