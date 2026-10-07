<?php

namespace App\Discovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DiscoveryCandidateService
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array{candidate_id:string,created:bool,deduplication_state:string} */
    public function add(string $tenantId, string $runId, array $row): array
    {
        $original = (string) ($row['website'] ?? $row['domain'] ?? '');
        try {
            $normalized = $this->domains->normalize($original);
            $domain = $normalized['normalized_domain'];
            $url = $normalized['normalized_url'];
        } catch (Throwable) {
            $domain = null;
            $url = null;
        }

        $sourceReference = isset($row['source_reference']) ? mb_substr((string) $row['source_reference'], 0, 2000) : null;
        if ($sourceReference !== null) {
            $byReference = DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('discovery_run_id', $runId)->where('source_reference', $sourceReference)->first();
            if ($byReference) return ['candidate_id' => $byReference->id, 'created' => false, 'deduplication_state' => $byReference->deduplication_state];
        }
        $existingCompany = $domain ? DB::table('companies')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->first(['id']) : null;
        $existingCandidate = $domain ? DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->first(['id']) : null;
        $state = ! $domain ? 'invalid_domain' : ($existingCompany ? 'existing_company' : ($existingCandidate ? 'duplicate_candidate' : 'new'));
        $id = (string) Str::uuid();
        DB::table('discovery_candidates')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'discovery_run_id' => $runId,
            'company_name' => isset($row['name']) ? mb_substr(trim((string) $row['name']), 0, 255) : null,
            'original_url' => mb_substr($original, 0, 2048), 'normalized_domain' => $domain,
            'country' => isset($row['country']) ? mb_substr(trim((string) $row['country']), 0, 100) : null,
            'city' => isset($row['city']) ? mb_substr(trim((string) $row['city']), 0, 120) : null,
            'industry' => isset($row['industry']) ? mb_substr(trim((string) $row['industry']), 0, 150) : null,
            'source' => mb_substr((string) ($row['source'] ?? 'csv_import'), 0, 80),
            'source_reference' => $sourceReference,
            'discovered_at' => now(), 'lifecycle_status' => $state === 'invalid_domain' ? 'rejected' : 'discovered',
            'verification_state' => $state === 'invalid_domain' ? 'invalid' : ($state === 'new' ? 'pending' : 'skipped'),
            'deduplication_state' => $state, 'deduplication_reason' => match ($state) {
                'existing_company' => 'A company with this normalized domain already exists in this tenant.',
                'duplicate_candidate' => 'A candidate with this normalized domain already exists.',
                'invalid_domain' => 'The URL is malformed or uses an unsupported scheme/host.', default => null,
            },
            'failure_code' => $state === 'invalid_domain' ? 'INVALID_DOMAIN' : null,
            'failure_summary' => $state === 'invalid_domain' ? 'Website URL could not be safely normalized.' : null,
            'confidence' => isset($row['confidence']) ? max(0, min(1, (float) $row['confidence'])) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($existingCompany) DB::table('discovery_candidates')->where('id', $id)->where('tenant_id', $tenantId)->update(['company_id' => $existingCompany->id]);

        return ['candidate_id' => $id, 'created' => true, 'deduplication_state' => $state];
    }
}
