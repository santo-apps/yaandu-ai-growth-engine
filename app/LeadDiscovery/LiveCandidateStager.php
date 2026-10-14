<?php

namespace App\LeadDiscovery;

use App\Discovery\DomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LiveCandidateStager
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array{created:bool,duplicate:bool,verification_id:?string} */
    public function stage(string $tenantId, object $campaign, object $run, object $source, object $checkpoint, array $result, int $rank): array
    {
        $name = trim((string) ($result['company_name'] ?? ''));
        $url = trim((string) ($result['website'] ?? ''));
        if ($name === '' || $url === '') return ['created' => false, 'duplicate' => false, 'verification_id' => null];
        try {
            $normalized = $this->domains->normalize($url);
            $domain = $normalized['normalized_domain'];
            if (filter_var($domain, FILTER_VALIDATE_IP)) return ['created' => false, 'duplicate' => false, 'verification_id' => null];
            $scheme = strtolower((string) parse_url($normalized['normalized_url'], PHP_URL_SCHEME));
            $website = $scheme.'://'.$domain.'/';
            $sourceUrl = $this->safeProvenanceUrl((string) ($result['source_url'] ?? $url));
        } catch (\Throwable) {
            return ['created' => false, 'duplicate' => false, 'verification_id' => null];
        }
        $fingerprint = hash('sha256', $domain);
        $existingCandidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('fingerprint', $fingerprint)->first(['id']);
        $company = DB::table('companies')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->first(['id']);
        if ($existingCandidate) return ['created' => false, 'duplicate' => true, 'verification_id' => null];

        $id = (string) Str::uuid();
        $now = now();
        DB::table('lead_discovery_candidates')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id,
            'fingerprint' => $fingerprint, 'company_name' => mb_substr($name, 0, 255), 'normalized_name' => mb_substr($this->normalizeName($name), 0, 255),
            'claimed_website' => $website, 'normalized_domain' => $domain, 'official_domain_status' => 'unverified',
            // Search query terms are not evidence of business industry or location.
            'industry' => null, 'country_code' => null, 'region' => null, 'city' => null, 'location' => null,
            'raw_metadata' => json_encode(['query_key' => $checkpoint->query_key, 'query' => mb_substr($checkpoint->query_text, 0, 300),
                'title' => mb_substr((string) ($result['title'] ?? ''), 0, 500), 'snippet' => mb_substr((string) ($result['snippet'] ?? ''), 0, 1000),
                'rank' => $rank, 'provider_request_id' => $result['_request_id'] ?? null, 'collected_at' => $now->toIso8601String()]),
            'processing_status' => 'discovered', 'validation_status' => 'pending', 'promotion_status' => 'not_promoted',
            'matched_company_id' => $company?->id, 'eligibility_status' => 'needs_review', 'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $attribution = ['source_key' => $source->source_key, 'source_id' => $source->id, 'run_id' => $run->id,
            'query_key' => $checkpoint->query_key, 'query' => mb_substr($checkpoint->query_text, 0, 300), 'rank' => $rank,
            'provider_request_id' => $result['_request_id'] ?? null, 'collected_at' => $now->toIso8601String(), 'authorization_status' => $source->authorization_status];
        foreach ([
            ['field' => 'company_name', 'value' => $name, 'type' => 'live_search_result'],
            ['field' => 'claimed_website', 'value' => $website, 'type' => 'live_search_result'],
            ['field' => 'search_snippet', 'value' => mb_substr((string) ($result['snippet'] ?? ''), 0, 1000), 'type' => 'live_search_result'],
        ] as $evidence) {
            if ($evidence['value'] === '') continue;
            DB::table('lead_discovery_candidate_evidence')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'candidate_id' => $id, 'campaign_id' => $campaign->id, 'source_id' => $source->id,
                'field_name' => $evidence['field'], 'evidence_type' => $evidence['type'], 'source_url' => $sourceUrl,
                'raw_value' => mb_substr($evidence['value'], 0, 2000), 'normalized_value' => $evidence['field'] === 'claimed_website' ? $domain : mb_substr($evidence['value'], 0, 2000),
                'confidence_score' => 0.5, 'collected_at' => $now, 'attribution' => json_encode($attribution), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => null,
            'action' => 'lead_discovery.candidate_discovered', 'subject_type' => 'lead_discovery', 'subject_id' => $id,
            'metadata' => json_encode(['campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key]), 'created_at' => $now]);
        return ['created' => true, 'duplicate' => (bool) $company, 'verification_id' => $id];
    }

    private function safeProvenanceUrl(string $url): ?string
    {
        try {
            $normalized = $this->domains->normalize($url);
            $scheme = strtolower((string) parse_url($normalized['normalized_url'], PHP_URL_SCHEME));
            return $scheme.'://'.$normalized['normalized_domain'].'/'.ltrim((string) parse_url($normalized['normalized_url'], PHP_URL_PATH), '/');
        } catch (\Throwable) { return null; }
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\pL\pN ]/u', ' ', $name) ?: $name)) ?: '');
    }
}
