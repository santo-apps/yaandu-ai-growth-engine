<?php

namespace App\WebsiteResolution;

use App\Jobs\ResolveWebsiteCandidateJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebsiteResolutionService
{
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
                ->whereIn('state', ['PENDING', 'SEARCHING', 'CANDIDATES_FOUND', 'VERIFYING'])->orderByDesc('created_at')->first();
            if ($active) return $active;
            $run = DB::table('discovery_runs')->where('tenant_id', $tenantId)->where('id', $candidate->discovery_run_id)->first();
            abort_unless($run, 404);
            if (DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('discovery_run_id', $run->id)->count() >= (int) config('website_resolution.max_businesses_per_run', 25)) {
                throw ValidationException::withMessages(['candidate' => ['The configured per-run website-resolution budget has been reached.']]);
            }
            $id = (string) Str::uuid();
            $snapshot = app(WebsiteIdentitySnapshotFactory::class)->make($tenantId, $candidate);
            DB::table('website_resolutions')->insert(['id' => $id, 'tenant_id' => $tenantId, 'candidate_id' => $candidateId,
                'discovery_run_id' => $candidate->discovery_run_id, 'requested_by' => $actorId, 'state' => 'PENDING',
                'identity_snapshot' => json_encode($snapshot), 'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now()]);
            ResolveWebsiteCandidateJob::dispatch($tenantId, $id)->afterCommit();
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
