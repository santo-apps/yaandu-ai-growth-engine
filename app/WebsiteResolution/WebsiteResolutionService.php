<?php

namespace App\WebsiteResolution;

use App\Jobs\RunCandidateDomainDiscoveryJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Company;

final class WebsiteResolutionService
{
    public function requestForCompany(string $tenantId, string $companyId, int $actorId, string $idempotencyKey): object
    {
        $company = Company::query()->where('tenant_id', $tenantId)->where('status', '!=', 'discovery_candidate')->with('websites')->findOrFail($companyId);
        if ($company->normalized_domain || $company->websites->isNotEmpty()) {
            throw ValidationException::withMessages(['company' => ['Website discovery is available only when this prospect has no website.']]);
        }

        $candidateId = DB::transaction(function () use ($tenantId, $company, $actorId): string {
            Company::query()->where('tenant_id', $tenantId)->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('company_id', $company->id)
                ->whereNull('normalized_domain')->where('verification_state', 'not_required')->orderByDesc('created_at')->first();
            if ($existing) return (string) $existing->id;

            $searchId = (string) Str::uuid();
            $runId = (string) Str::uuid();
            $candidateId = (string) Str::uuid();
            $now = now();
            DB::table('discovery_searches')->insert(['id' => $searchId, 'tenant_id' => $tenantId,
                'name' => 'Website discovery · '.mb_substr((string) $company->name, 0, 100), 'status' => 'completed',
                'criteria' => json_encode(['company_id' => $company->id, 'explicit_request' => true]), 'source' => 'manual_company',
                'max_candidates' => 1, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'discovery_search_id' => $searchId,
                'requested_by' => $actorId, 'status' => 'completed', 'started_at' => $now, 'completed_at' => $now,
                'counts' => json_encode(['found' => 1, 'duplicates' => 0, 'invalid' => 0, 'verified' => 0, 'analyzed' => 0, 'scored' => 0, 'accepted' => 0, 'rejected' => 0, 'failures' => 0]),
                'source_counts' => json_encode(['manual_company' => 1]), 'budget' => json_encode(['max_candidates' => 1, 'source' => 'explicit_company_request']),
                'idempotency_key' => 'manual-company:'.$company->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('discovery_candidates')->insert(['id' => $candidateId, 'tenant_id' => $tenantId, 'discovery_run_id' => $runId,
                'company_id' => $company->id, 'company_name' => $company->name, 'original_url' => null, 'normalized_domain' => null,
                'city' => $company->location ? mb_substr($company->location, 0, 120) : null, 'country' => null, 'industry' => $company->industry, 'source' => 'manual_company',
                'source_reference' => 'company:'.$company->id, 'discovered_at' => $now, 'lifecycle_status' => 'discovered',
                'verification_state' => 'not_required', 'deduplication_state' => 'new', 'import_state' => 'pending',
                'analysis_status' => 'not_eligible', 'eligible_for_analysis' => false, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('discovery_candidate_sources')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                'candidate_id' => $candidateId, 'discovery_run_id' => $runId, 'source' => 'manual_company',
                'source_reference' => 'company:'.$company->id, 'source_timestamp' => $now, 'discovered_at' => $now,
                'source_metadata' => json_encode(['explicitly_entered_company_profile' => true]), 'created_at' => $now, 'updated_at' => $now]);

            return $candidateId;
        });

        return $this->request($tenantId, $candidateId, $actorId, $idempotencyKey);
    }

    public function request(string $tenantId, string $candidateId, int $actorId, string $idempotencyKey): object
    {
        abort_unless(config('website_resolution.enabled', true), 503, 'Website resolution is disabled by configuration.');
        return DB::transaction(function () use ($tenantId, $candidateId, $actorId, $idempotencyKey): object {
            $existing = DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->candidate_id !== $candidateId) throw ValidationException::withMessages(['idempotency_key' => ['This key was already used for a different candidate.']]);
                return $existing;
            }
            $candidate = DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->lockForUpdate()->first();
            abort_unless($candidate, 404);
            if ($candidate->normalized_domain || $candidate->verification_state !== 'not_required') {
                throw ValidationException::withMessages(['candidate' => ['Only website-less candidates can enter website resolution.']]);
            }
            $active = DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('candidate_id', $candidateId)
                ->whereIn('state', ['PENDING', 'SEARCHING', 'CANDIDATES_FOUND', 'PARTIALLY_COMPLETED', 'VERIFYING'])->orderByDesc('created_at')->first();
            if ($active) return $active;
            $run = DB::table('discovery_runs')->where('tenant_id', $tenantId)->where('id', $candidate->discovery_run_id)->first();
            abort_unless($run, 404);
            if (DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('discovery_run_id', $run->id)->count() >= (int) config('website_resolution.max_businesses_per_run', 25)) {
                throw ValidationException::withMessages(['candidate' => ['The configured per-run website-resolution budget has been reached.']]);
            }
            $id = (string) Str::uuid();
            $snapshot = app(WebsiteIdentitySnapshotFactory::class)->make($tenantId, $candidate);
            DB::table('website_resolutions')->insert(['id' => $id, 'tenant_id' => $tenantId, 'candidate_id' => $candidateId,
                'discovery_run_id' => $candidate->discovery_run_id, 'requested_by' => $actorId, 'state' => 'PENDING', 'discovery_status' => 'PENDING',
                'identity_snapshot' => json_encode($snapshot), 'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now()]);
            RunCandidateDomainDiscoveryJob::dispatch($tenantId, $id)->afterCommit();
            return DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $id)->first();
        });
    }

    public function retry(string $tenantId, string $resolutionId, int $actorId, string $idempotencyKey): object
    {
        $resolution = DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $resolutionId)->first();
        abort_unless($resolution, 404);
        return $this->request($tenantId, $resolution->candidate_id, $actorId, $idempotencyKey);
    }
}
