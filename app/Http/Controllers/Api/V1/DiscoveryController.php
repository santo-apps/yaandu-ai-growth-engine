<?php

namespace App\Http\Controllers\Api\V1;

use App\Discovery\DiscoveryCandidateService;
use App\Discovery\CheapCandidateFilter;
use App\Discovery\DiscoveryAnalysisBudget;
use App\Discovery\DiscoveryQuery;
use App\Discovery\DiscoverySourceRegistry;
use App\Discovery\DomainNormalizer;
use App\Contacts\ContactMethodValue;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Jobs\AnalyzeAndScoreDiscoveryCandidateJob;
use App\Jobs\VerifyDiscoveryCandidateJob;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DiscoveryController extends Controller
{
    public function searches()
    {
        return DB::table('discovery_searches')->where('tenant_id', app('tenant.id'))->orderByDesc('created_at')->paginate(25);
    }

    public function sourceHealth(Request $request, \App\Discovery\DiscoverySourceHealth $health)
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status', 'active')->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Discovery source diagnostics require an active owner or admin.');
        return response()->json($health->status());
    }

    public function createSearch(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'source' => ['required', 'in:deterministic_local,supplied_seed,location_open_web'],
            'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'], 'region' => ['nullable', 'string', 'max:100'],
            'business_category' => ['nullable', 'string', 'max:100'], 'radius_m' => ['nullable', 'integer', 'min:500', 'max:25000'],
            'locations' => ['nullable', 'array', 'max:20'], 'locations.*' => ['string', 'max:100'],
            'industries' => ['nullable', 'array', 'max:20'], 'industries.*' => ['string', 'max:150'],
            'keywords' => ['nullable', 'array', 'max:30'], 'keywords.*' => ['string', 'max:100'],
            'website_criteria' => ['nullable', 'array', 'max:20'], 'website_criteria.*' => ['string', 'max:100'],
            'desired_services' => ['nullable', 'array', 'max:20'], 'desired_services.*' => ['string', 'max:150'],
            'company_size' => ['nullable', 'in:SMB,Mid-market,Enterprise,Unknown'],
            'max_candidates' => ['sometimes', 'integer', 'min:1', 'max:100'], 'seeds' => ['nullable', 'array', 'max:100'],
            'seeds.*.website' => ['required_with:seeds', 'string', 'max:2048'], 'seeds.*.name' => ['nullable', 'string', 'max:255'],
            'seeds.*.industry' => ['nullable', 'string', 'max:150'], 'seeds.*.country' => ['nullable', 'string', 'max:100'], 'seeds.*.city' => ['nullable', 'string', 'max:120'],
        ]);
        if (($data['source'] ?? null) === 'deterministic_local' && (! app()->environment(['local', 'testing']) || ! config('discovery.allow_deterministic', false))) {
            return response()->json(['message' => 'Deterministic discovery is disabled in this environment.'], 422);
        }
        if (($data['source'] ?? null) === 'location_open_web' && (! config('discovery.osm_enabled', false) || empty($data['city']))) {
            throw ValidationException::withMessages(['city' => ['Choose a supported city for location discovery.']]);
        }
        $tenant = app('tenant.id');
        $id = (string) Str::uuid();
        DB::table('discovery_searches')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'name' => $data['name'], 'source' => $data['source'],
            'criteria' => json_encode(collect($data)->except(['name', 'source', 'max_candidates'])->all()),
            'max_candidates' => min((int) ($data['max_candidates'] ?? 25), (int) config('discovery.max_candidates_per_run', 100)),
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($tenant, $request->user()->id, 'discovery_search_created', $id, ['source' => $data['source']]);
        return response()->json(DB::table('discovery_searches')->where('tenant_id', $tenant)->where('id', $id)->first(), 201);
    }

    public function startRun(Request $request, string $search)
    {
        $tenant = app('tenant.id');
        $record = DB::table('discovery_searches')->where('tenant_id', $tenant)->where('id', $search)->first();
        abort_unless($record, 404);
        $data = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:128']]);
        $key = $data['idempotency_key'] ?? $request->header('Idempotency-Key');
        if ($key) {
            $existing = DB::table('discovery_runs')->where('tenant_id', $tenant)->where('idempotency_key', $key)->first();
            if ($existing) return response()->json($existing, 200);
        }
        $limit = min((int) $record->max_candidates, (int) config('discovery.max_candidates_per_run', 100), (int) config('discovery.max_websites_verified_per_run', 100));
        $runId = (string) Str::uuid(); $agentRunId = (string) Str::uuid(); $correlation = (string) Str::uuid();
        $seeds = json_decode((string) ($record->criteria ?? '{}'), true)['seeds'] ?? [];
        DB::transaction(function () use ($tenant, $record, $runId, $agentRunId, $correlation, $key, $request, $limit, $seeds): void {
            DB::table('agent_runs')->insert(['id' => $agentRunId, 'tenant_id' => $tenant, 'agent_key' => 'DiscoveryAgent', 'status' => 'queued',
                'requested_by' => $request->user()->id, 'input_hash' => hash('sha256', json_encode([$record->source, $runId])), 'correlation_id' => $correlation,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant, 'discovery_search_id' => $record->id,
                'agent_run_id' => $agentRunId, 'requested_by' => $request->user()->id, 'status' => 'queued', 'idempotency_key' => $key,
                'started_at' => now(),
                'counts' => json_encode(['found' => 0, 'duplicates' => 0, 'invalid' => 0, 'verified' => 0, 'analyzed' => 0, 'scored' => 0, 'accepted' => 0, 'rejected' => 0, 'failures' => 0]),
                'source_counts' => '{}',
                'budget' => json_encode(['max_candidates' => $limit, 'max_pages_per_domain' => (int) config('discovery.max_pages_per_domain', 10), 'max_browser_renders' => (int) config('discovery.max_browser_renders_per_run', 0), 'max_ai_analyses' => (int) config('discovery.max_ai_analyses_per_run', 25), 'max_runtime_seconds' => (int) config('discovery.max_runtime_seconds', 600)]),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('agent_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'agent_run_id' => $agentRunId,
                'sequence' => 1, 'event_key' => 'discovery_run_queued', 'payload' => json_encode(['discovery_run_id' => $runId, 'source' => $record->source]), 'created_at' => now()]);
            $input = ['source' => $record->source, 'discovery_run_id' => $runId, 'query' => json_decode((string) $record->criteria, true) ?: [], 'limit' => $limit, 'candidates' => $seeds];
            RunAgentJob::dispatch($tenant, 'DiscoveryAgent', $input, (string) $request->user()->id, $agentRunId)->afterCommit();
        });
        $this->audit($tenant, $request->user()->id, 'discovery_run_started', $runId, ['search_id' => $record->id, 'source' => $record->source, 'limit' => $limit]);
        return response()->json(DB::table('discovery_runs')->where('tenant_id', $tenant)->where('id', $runId)->first(), 202);
    }

    public function runs()
    {
        return DB::table('discovery_runs')->where('tenant_id', app('tenant.id'))->orderByDesc('created_at')->paginate(25);
    }

    public function showRun(string $run)
    {
        $tenant = app('tenant.id');
        $record = DB::table('discovery_runs')->where('tenant_id', $tenant)->where('id', $run)->first();
        abort_unless($record, 404);
        return response()->json(['run' => $record, 'candidates' => DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('discovery_run_id', $run)->orderByDesc('created_at')->paginate(50)]);
    }

    public function candidates(Request $request)
    {
        $tenant = app('tenant.id');
        $page = DB::table('discovery_candidates')->where('tenant_id', $tenant)
            ->when($request->filled('run_id'), fn ($query) => $query->where('discovery_run_id', $request->string('run_id')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('lifecycle_status', $request->string('status')->toString()))
            ->when($request->filled('location'), fn ($query) => $query->where(fn ($q) => $q->where('country', $request->string('location')->toString())->orWhere('city', $request->string('location')->toString())))
            ->when($request->filled('industry'), fn ($query) => $query->where('industry', $request->string('industry')->toString()))
            ->orderByDesc('created_at')->paginate(50);
        $page->getCollection()->transform(function ($candidate) use ($tenant) {
            $candidate->sources = DB::table('discovery_candidate_sources')->where('tenant_id', $tenant)->where('candidate_id', $candidate->id)->pluck('source')->unique()->values();
            $candidate->source_count = $candidate->sources->count();
            $candidate->contact_count = $candidate->company_id ? DB::table('contacts')->where('tenant_id', $tenant)->where('company_id', $candidate->company_id)->count() : 0;
            $score = $candidate->company_id ? DB::table('lead_scores')->where('tenant_id', $tenant)->where('company_id', $candidate->company_id)->orderByDesc('scored_at')->first(['score', 'components']) : null;
            $candidate->lead_score = $score?->score;
            $candidate->score_components = $score ? (is_array($score->components) ? $score->components : json_decode($score->components ?? '{}', true)) : null;
            $candidate->review_ready = $candidate->lifecycle_status === 'reviewable' && $candidate->lead_score !== null;
            return $candidate;
        });
        return $page;
    }

    public function candidate(string $candidate)
    {
        $tenant = app('tenant.id');
        $row = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->first();
        abort_unless($row, 404);
        $company = $row->company_id ? Company::where('tenant_id', $tenant)->where('id', $row->company_id)->with('websites')->first() : null;
        $intelligence = $company ? DB::table('lead_insights')->where('tenant_id', $tenant)->where('company_id', $company->id)->orderByDesc('created_at')->limit(20)->get() : collect();
        $issues = $company ? DB::table('website_issues')->where('tenant_id', $tenant)->whereIn('website_scan_id',
            DB::table('website_scans as scans')->join('company_websites as websites', function ($join) use ($tenant): void {
                $join->on('websites.id', '=', 'scans.company_website_id')->where('websites.tenant_id', '=', $tenant);
            })->where('scans.tenant_id', $tenant)->where('websites.company_id', $company->id)->select('scans.id'))
            ->orderByDesc('created_at')->limit(30)->get() : collect();
        $contacts = $company ? DB::table('contacts')->where('tenant_id', $tenant)->where('company_id', $company->id)->orderByDesc('observed_at')->limit(30)->get() : collect();
        $contactValues = app(ContactMethodValue::class);
        $contacts->transform(function ($contact) use ($tenant, $contactValues) {
            $contact->methods = DB::table('contact_methods')->where('tenant_id', $tenant)->where('contact_id', $contact->id)->get()
                ->map(function ($method) use ($contactValues) { $method->value = $contactValues->decrypt($method->value); return $method; });
            return $contact;
        });
        $scores = $company ? DB::table('lead_scores')->where('tenant_id', $tenant)->where('company_id', $company->id)->orderByDesc('scored_at')->limit(5)->get() : collect();
        $sources = DB::table('discovery_candidate_sources')->where('tenant_id', $tenant)->where('candidate_id', $row->id)->orderBy('source')->get()
            ->map(function ($source): object { $source->source_metadata = is_array($source->source_metadata) ? $source->source_metadata : json_decode((string) $source->source_metadata, true); return $source; });
        return response()->json(['candidate' => $row, 'company' => $company, 'findings' => $intelligence, 'website_issues' => $issues, 'contacts' => $contacts, 'scores' => $scores, 'sources' => $sources]);
    }

    public function previewImport(Request $request, DomainNormalizer $domains)
    {
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $rows = $this->parseCsv($request->file('csv')->getRealPath());
        $tenant = app('tenant.id'); $seen = []; $knownDomains = DB::table('discovery_candidates')->where('tenant_id', $tenant)->whereNotNull('normalized_domain')->pluck('normalized_domain')->flip(); $valid = 0; $invalid = 0; $duplicates = 0; $preview = [];
        foreach ($rows as $index => $row) {
            try {
                $normalized = $domains->normalize($row['website'] ?? $row['domain'] ?? '');
                $domain = $normalized['normalized_domain'];
                $exists = DB::table('companies')->where('tenant_id', $tenant)->where('normalized_domain', $domain)->exists();
                $withinFile = isset($seen[$domain]);
                $duplicate = $exists || $withinFile || $knownDomains->has($domain);
                if ($duplicate) $duplicates++; else $valid++;
                $seen[$domain] = true;
                $preview[] = ['row' => $index + 2, 'company_name' => $row['company_name'] ?? $row['name'] ?? null, 'website' => $row['website'] ?? $row['domain'], 'normalized_domain' => $domain, 'country' => $row['country'] ?? null, 'city' => $row['city'] ?? null, 'industry' => $row['industry'] ?? null, 'status' => $duplicate ? 'duplicate' : 'valid', 'duplicate_type' => $withinFile ? 'within_file' : ($exists ? 'existing_company' : ($knownDomains->has($domain) ? 'existing_candidate' : null))];
            } catch (Throwable) { $invalid++; $preview[] = ['row' => $index + 2, 'website' => $row['website'] ?? $row['domain'] ?? null, 'status' => 'invalid', 'reason' => 'Invalid or unsupported website domain.']; }
        }
        return response()->json(['rows' => $preview, 'total' => count($rows), 'valid_count' => $valid, 'invalid_count' => $invalid, 'duplicate_count' => $duplicates,
            'row_limit' => min((int) config('discovery.max_csv_rows', 500), (int) config('discovery.max_candidates_per_run', 100), (int) config('discovery.max_websites_verified_per_run', 100)), 'requires_confirmation' => true]);
    }

    public function import(Request $request)
    {
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'confirmed' => ['required', 'accepted'], 'idempotency_key' => ['nullable', 'string', 'max:128']]);
        $tenant = app('tenant.id');
        $key = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');
        if ($key) {
            $existing = DB::table('discovery_runs')->where('tenant_id', $tenant)->where('idempotency_key', $key)->first();
            if ($existing) return response()->json(['run_id' => $existing->id, 'status' => $existing->status,
                'candidate_count' => DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('discovery_run_id', $existing->id)->count(), 'replayed' => true], 200);
        }
        $rows = $this->parseCsv($request->file('csv')->getRealPath());
        if (count($rows) > (int) config('discovery.max_csv_rows', 500)) throw ValidationException::withMessages(['csv' => ['The CSV exceeds the configured row limit.']]);
        $importLimit = min((int) config('discovery.max_candidates_per_run', 100), (int) config('discovery.max_websites_verified_per_run', 100));
        if (count($rows) > $importLimit) throw ValidationException::withMessages(['csv' => ['Split this file into separate imports; the configured per-run website processing limit is '.$importLimit.'.']]);
        $actor = (string) $request->user()->id; $runId = (string) Str::uuid(); $agentRunId = (string) Str::uuid(); $correlation = (string) Str::uuid();
        $sourceRows = app(DiscoverySourceRegistry::class)->get('csv_import')->search(new DiscoveryQuery([], count($rows), $rows));
        DB::transaction(function () use ($tenant, $actor, $runId, $agentRunId, $correlation, $sourceRows, $key, $importLimit): void {
            $searchId = (string) Str::uuid();
            DB::table('discovery_searches')->insert(['id' => $searchId, 'tenant_id' => $tenant, 'name' => 'CSV import '.now()->format('Y-m-d H:i'), 'status' => 'active', 'criteria' => '{}', 'source' => 'csv_import', 'max_candidates' => min(count($sourceRows), (int) config('discovery.max_candidates_per_run', 100)), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('agent_runs')->insert(['id' => $agentRunId, 'tenant_id' => $tenant, 'agent_key' => 'DiscoveryAgent', 'status' => 'running', 'requested_by' => $actor,
                'input_hash' => hash('sha256', json_encode($sourceRows)), 'input_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($sourceRows)), 'correlation_id' => $correlation, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant, 'discovery_search_id' => $searchId, 'agent_run_id' => $agentRunId, 'requested_by' => $actor,
                'status' => 'verifying', 'started_at' => now(), 'idempotency_key' => $key, 'source_counts' => json_encode(['csv_import' => count($sourceRows)]), 'budget' => json_encode(['max_candidates' => min(count($sourceRows), $importLimit), 'max_ai_analyses' => (int) config('discovery.max_ai_analyses_per_run', 25), 'max_pages_per_domain' => (int) config('discovery.max_pages_per_domain', 10), 'max_browser_renders' => 0, 'max_runtime_seconds' => (int) config('discovery.max_runtime_seconds', 600), 'source' => 'csv_import']), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($sourceRows as $rowIndex => $row) {
                $row['source'] = 'csv_import';
                $row['source_reference'] = 'csv-row-'.($rowIndex + 2);
                $candidate = app(DiscoveryCandidateService::class)->add($tenant, $runId, ['name' => $row['company_name'] ?? $row['name'] ?? null, ...$row]);
                if ($candidate['created'] && $candidate['deduplication_state'] === 'new') VerifyDiscoveryCandidateJob::dispatch($tenant, $runId, $candidate['candidate_id'])->afterCommit();
            }
            $this->refreshImportRun($tenant, $runId);
            $count = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('discovery_run_id', $runId)->count();
            DB::table('agent_runs')->where('tenant_id', $tenant)->where('id', $agentRunId)->update(['status' => 'succeeded', 'summary' => 'Imported '.$count.' reviewed candidate rows.', 'finished_at' => now(), 'updated_at' => now()]);
        });
        $this->audit($tenant, $actor, 'discovery_csv_imported', $runId, ['row_count' => count($rows)]);
        return response()->json(['run_id' => $runId, 'status' => 'verifying', 'candidate_count' => min(count($rows), $importLimit), 'replayed' => false], 202);
    }

    public function review(Request $request, string $candidate)
    {
        $tenant = app('tenant.id');
        $data = $request->validate(['action' => ['required', 'in:accept,reject'], 'reason' => ['nullable', 'string', 'max:500']]);
        return DB::transaction(function () use ($tenant, $candidate, $data, $request) {
            $row = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->lifecycle_status === 'accepted' && $row->company_id) {
                $company = Company::where('tenant_id', $tenant)->where('id', $row->company_id)->where('status', '!=', 'discovery_candidate')->firstOrFail();
                return response()->json(['candidate_id' => $candidate, 'status' => 'accepted', 'company' => $company->load('websites'), 'replayed' => true]);
            }
            if ($data['action'] === 'reject') {
                DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->update(['lifecycle_status' => 'rejected', 'import_state' => 'rejected', 'rejection_reason' => $data['reason'] ?? 'Rejected during human review.', 'updated_at' => now()]);
                $this->audit($tenant, $request->user()->id, 'discovery_candidate_rejected', $candidate, ['reason' => $data['reason'] ?? null]);
                $this->refreshImportRun($tenant, $row->discovery_run_id);
                return response()->json(['candidate_id' => $candidate, 'status' => 'rejected']);
            }
            if ($row->verification_state !== 'verified') return response()->json(['message' => 'Candidate verification must succeed before acceptance.'], 422);
            if ($row->deduplication_state === 'existing_company' && $row->company_id) {
                $company = Company::where('tenant_id', $tenant)->where('id', $row->company_id)->where('status', '!=', 'discovery_candidate')->firstOrFail();
            } else {
                if ($row->lifecycle_status !== 'reviewable') return response()->json(['message' => 'Website intelligence and lead scoring must finish before candidate acceptance.'], 422);
                $company = Company::where('tenant_id', $tenant)->where('id', $row->company_id)->where('status', 'discovery_candidate')->lockForUpdate()->first();
                abort_unless($company && DB::table('lead_scores')->where('tenant_id', $tenant)->where('company_id', $company->id)->exists(), 422, 'A completed lead score is required before promotion.');
                $company->status = 'new';
                $company->description = trim(($company->description ? $company->description.' ' : '').'Promoted from reviewed discovery candidate '.$candidate.'.');
                $company->save();
            }
            DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->update(['company_id' => $company->id, 'lifecycle_status' => 'accepted', 'import_state' => 'accepted', 'updated_at' => now()]);
            $this->audit($tenant, $request->user()->id, 'discovery_candidate_accepted', $candidate, ['company_id' => $company->id, 'pre_promotion_score' => DB::table('lead_scores')->where('tenant_id', $tenant)->where('company_id', $company->id)->orderByDesc('scored_at')->value('score')]);
            $this->refreshImportRun($tenant, $row->discovery_run_id);
            return response()->json(['candidate_id' => $candidate, 'status' => 'accepted', 'company' => $company->load('websites')]);
        });
    }

    public function bulkReview(Request $request)
    {
        $data = $request->validate(['action' => ['required', 'in:accept,reject,reverify,analyze,intelligence,scoring'], 'candidate_ids' => ['required', 'array', 'min:1', 'max:100'], 'candidate_ids.*' => ['required', 'uuid']]);
        $results = [];
        $queued = 0;
        foreach (array_unique($data['candidate_ids']) as $id) {
            if ($data['action'] === 'accept' || $data['action'] === 'reject') {
                $result = $this->review($request->merge(['action' => $data['action']]), $id);
                $results[] = ['candidate_id' => $id, 'status' => $result->getStatusCode() < 300 ? 'processed' : 'failed', 'http_status' => $result->getStatusCode()];
                continue;
            }
            $candidate = DB::table('discovery_candidates')->where('tenant_id', app('tenant.id'))->where('id', $id)->first();
            if (! $candidate) { $results[] = ['candidate_id' => $id, 'status' => 'failed', 'reason' => 'Candidate not found in this tenant.']; continue; }
            if ($data['action'] === 'reverify' && in_array($candidate->verification_state, ['failed', 'unreachable', 'robots_denied'], true) && $candidate->normalized_domain) {
                DB::table('discovery_candidates')->where('tenant_id', app('tenant.id'))->where('id', $id)->update(['verification_state' => 'pending', 'lifecycle_status' => 'discovered', 'failure_code' => null, 'failure_summary' => null, 'updated_at' => now()]);
                VerifyDiscoveryCandidateJob::dispatch(app('tenant.id'), $candidate->discovery_run_id, $candidate->id)->afterCommit();
                $results[] = ['candidate_id' => $id, 'status' => 'queued'];
                $queued++;
                continue;
            }
            if (in_array($data['action'], ['analyze', 'intelligence', 'scoring'], true) && $candidate->company_id && $candidate->verification_state === 'verified') {
                $stage = $data['action'] === 'scoring' ? 'scoring' : 'all';
                if ($candidate->lifecycle_status === 'reviewable' || $candidate->analysis_status === 'completed') {
                    $results[] = ['candidate_id' => $id, 'status' => 'skipped', 'reason' => 'Analysis and scoring are already complete.'];
                    continue;
                }
                $filter = app(CheapCandidateFilter::class)->evaluate($candidate);
                if (! $filter['eligible']) {
                    DB::table('discovery_candidates')->where('tenant_id', app('tenant.id'))->where('id', $id)->update([
                        'eligible_for_analysis' => false, 'analysis_status' => 'not_eligible', 'analysis_reason' => $filter['reason'], 'updated_at' => now(),
                    ]);
                    $results[] = ['candidate_id' => $id, 'status' => 'skipped', 'reason' => $filter['reason']];
                    continue;
                }
                DB::table('discovery_candidates')->where('tenant_id', app('tenant.id'))->where('id', $id)->update([
                    'eligible_for_analysis' => true, 'analysis_status' => 'eligible', 'analysis_reason' => $filter['reason'], 'updated_at' => now(),
                ]);
                if (! app(DiscoveryAnalysisBudget::class)->reserve((string) app('tenant.id'), $id)) {
                    $results[] = ['candidate_id' => $id, 'status' => 'skipped', 'reason' => 'Analysis budget reached for this run.'];
                    continue;
                }
                if ($stage === 'scoring' && ! in_array($candidate->lifecycle_status, ['analyzed', 'reviewable'], true)) {
                    $results[] = ['candidate_id' => $id, 'status' => 'skipped', 'reason' => 'Website intelligence must complete before scoring.'];
                    continue;
                }
                AnalyzeAndScoreDiscoveryCandidateJob::dispatch(app('tenant.id'), $candidate->id, (string) $request->user()->id, $stage)->afterCommit();
                $results[] = ['candidate_id' => $id, 'status' => 'queued'];
                $queued++;
                continue;
            }
            $results[] = ['candidate_id' => $id, 'status' => 'skipped', 'reason' => 'This action does not apply to the candidate state.'];
        }
        $this->audit(app('tenant.id'), $request->user()->id, 'discovery_bulk_review', null, ['action' => $data['action'], 'count' => count($results)]);
        $processed = count(array_filter($results, fn (array $row): bool => $row['status'] === 'processed'));
        $notActionable = count(array_filter($results, fn (array $row): bool => in_array($row['status'], ['failed', 'skipped'], true)));
        $payload = ['action' => $data['action'], 'candidate_count' => count($results), 'queued_count' => $queued,
            'processed_count' => $processed, 'not_actionable_count' => $notActionable, 'results' => $results];
        return response()->json($payload, $queued > 0 ? 202 : 200);
    }

    private function parseCsv(string $path): array
    {
        $stream = fopen($path, 'rb');
        if (! $stream) throw ValidationException::withMessages(['csv' => ['The CSV file could not be read.']]);
        $headers = fgetcsv($stream);
        if (! is_array($headers)) throw ValidationException::withMessages(['csv' => ['The CSV has no header row.']]);
        $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $headers);
        if (! in_array('website', $headers, true) && ! in_array('domain', $headers, true)) throw ValidationException::withMessages(['csv' => ['Include a website or domain column.']]);
        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if (count(array_filter($values, static fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= (int) config('discovery.max_csv_rows', 500)) { fclose($stream); throw ValidationException::withMessages(['csv' => ['The CSV exceeds the configured row limit.']]); }
            $row = []; foreach ($headers as $i => $header) $row[$header] = trim((string) ($values[$i] ?? ''));
            $website = $row['website'] ?? $row['domain'] ?? '';
            if ($website === '') { $row['website'] = ''; } else $row['website'] = mb_substr($website, 0, 2048);
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private function refreshImportRun(string $tenant, string $runId): void
    {
        $counts = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('discovery_run_id', $runId)
            ->selectRaw('count(*) as found, sum(case when deduplication_state in (\'existing_company\', \'duplicate_candidate\') then 1 else 0 end) as duplicates, sum(case when verification_state = \'invalid\' then 1 else 0 end) as invalid, sum(case when verification_state = \'not_required\' then 1 else 0 end) as no_website, sum(case when eligible_for_analysis then 1 else 0 end) as eligible_for_analysis, sum(case when verification_state = \'verified\' then 1 else 0 end) as verified, sum(case when lifecycle_status in (\'analyzed\', \'reviewable\', \'accepted\') then 1 else 0 end) as analyzed, sum(case when lifecycle_status in (\'reviewable\', \'accepted\') then 1 else 0 end) as scored, sum(case when lifecycle_status = \'accepted\' then 1 else 0 end) as accepted, sum(case when lifecycle_status = \'rejected\' then 1 else 0 end) as rejected, sum(case when verification_state in (\'failed\', \'unreachable\', \'robots_denied\') or lifecycle_status = \'analysis_failed\' then 1 else 0 end) as failures')->first();
        $pending = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('discovery_run_id', $runId)
            ->where(function ($query): void { $query->where('verification_state', 'pending')->orWhereIn('lifecycle_status', ['verified', 'analyzing', 'analyzed']); })->exists();
        DB::table('discovery_runs')->where('tenant_id', $tenant)->where('id', $runId)->update(['status' => $pending ? 'analyzing' : 'completed', 'completed_at' => $pending ? null : now(), 'counts' => json_encode($counts), 'updated_at' => now()]);
    }

    private function audit(string $tenant, ?int $actor, string $action, ?string $subject, array $metadata): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $actor, 'action' => $action,
            'subject_type' => $subject ? 'discovery' : null, 'subject_id' => $subject, 'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
