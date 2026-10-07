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
        $original = trim((string) ($row['website'] ?? $row['domain'] ?? ''));
        $hasWebsite = $original !== '';
        try {
            if (! $hasWebsite) throw new \InvalidArgumentException('No website supplied.');
            $normalized = $this->domains->normalize($original);
            $domain = $normalized['normalized_domain'];
            $url = $normalized['normalized_url'];
            $invalid = false;
        } catch (Throwable) {
            $domain = null;
            $url = null;
            $invalid = $hasWebsite;
        }

        $sourceReference = isset($row['source_reference']) ? mb_substr((string) $row['source_reference'], 0, 2000) : null;
        if ($sourceReference !== null) {
            $byReference = DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('discovery_run_id', $runId)->where('source_reference', $sourceReference)->first();
            if ($byReference) {
                $this->recordSource($tenantId, $runId, $byReference->id, $row);
                return ['candidate_id' => $byReference->id, 'created' => false, 'deduplication_state' => $byReference->deduplication_state];
            }
        }
        $existingCompany = $domain ? DB::table('companies')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->first(['id']) : null;
        $existingCandidate = $domain ? DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('discovery_run_id', $runId)->where('normalized_domain', $domain)->first(['id', 'source']) : null;
        if ($existingCandidate && (($row['source'] ?? null) === 'openstreetmap' || $existingCandidate->source !== ($row['source'] ?? null))) {
            $this->recordSource($tenantId, $runId, $existingCandidate->id, $row);
            return ['candidate_id' => $existingCandidate->id, 'created' => false, 'deduplication_state' => 'duplicate_candidate'];
        }
        $state = $invalid ? 'invalid_domain' : (! $hasWebsite ? 'no_website' : ($existingCompany ? 'existing_company' : ($existingCandidate ? 'duplicate_candidate' : 'new')));
        $id = (string) Str::uuid();
        DB::table('discovery_candidates')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'discovery_run_id' => $runId,
            'company_name' => isset($row['name']) ? mb_substr(trim((string) $row['name']), 0, 255) : null,
            'original_url' => $hasWebsite ? mb_substr($original, 0, 2048) : null, 'normalized_domain' => $domain,
            'country' => isset($row['country']) ? mb_substr(trim((string) $row['country']), 0, 100) : null,
            'city' => isset($row['city']) ? mb_substr(trim((string) $row['city']), 0, 120) : null,
            'industry' => isset($row['industry']) ? mb_substr(trim((string) $row['industry']), 0, 150) : null,
            'source' => mb_substr((string) ($row['source'] ?? 'csv_import'), 0, 80),
            'source_reference' => $sourceReference,
            'discovered_at' => now(), 'lifecycle_status' => $state === 'invalid_domain' ? 'rejected' : ($state === 'no_website' ? 'website_not_found' : 'discovered'),
            'verification_state' => $state === 'invalid_domain' ? 'invalid' : ($state === 'new' ? 'pending' : ($state === 'no_website' ? 'not_required' : 'skipped')),
            'deduplication_state' => $state, 'deduplication_reason' => match ($state) {
                'existing_company' => 'A company with this normalized domain already exists in this tenant.',
                'duplicate_candidate' => 'A candidate with this normalized domain already exists.',
                'invalid_domain' => 'The URL is malformed or uses an unsupported scheme/host.',
                'no_website' => 'No public business website was listed by this source.', default => null,
            },
            'failure_code' => $state === 'invalid_domain' ? 'INVALID_DOMAIN' : null,
            'failure_summary' => $state === 'invalid_domain' ? 'Website URL could not be safely normalized.' : null,
            'confidence' => isset($row['confidence']) ? max(0, min(1, (float) $row['confidence'])) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($existingCompany) DB::table('discovery_candidates')->where('id', $id)->where('tenant_id', $tenantId)->update(['company_id' => $existingCompany->id]);
        $this->recordSource($tenantId, $runId, $id, $row);

        return ['candidate_id' => $id, 'created' => true, 'deduplication_state' => $state];
    }

    private function recordSource(string $tenantId, string $runId, string $candidateId, array $row): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('discovery_candidate_sources')) return;
        $metadata = $row['source_metadata'] ?? [];
        $encoded = json_encode($metadata);
        if (strlen((string) $encoded) > 32000) $metadata = ['truncated' => true, 'summary' => array_intersect_key($metadata, array_flip(['osm_type', 'osm_id', 'lat', 'lon', 'query']))];
        $sourceAt = null;
        if (! empty($row['source_timestamp'])) {
            try { $sourceAt = \Illuminate\Support\Carbon::parse($row['source_timestamp']); } catch (Throwable) { $sourceAt = null; }
        }
        DB::table('discovery_candidate_sources')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'candidate_id' => $candidateId, 'discovery_run_id' => $runId,
            'source' => mb_substr((string) ($row['source'] ?? 'unknown'), 0, 80),
            'source_reference' => isset($row['source_reference']) ? mb_substr((string) $row['source_reference'], 0, 2000) : null,
            'source_timestamp' => $sourceAt, 'discovered_at' => now(),
            'source_query' => json_encode($row['source_query'] ?? ($metadata['query'] ?? [])), 'source_metadata' => json_encode($metadata),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
