<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\DB;

/** Operational, tenant-scoped reporting for the bounded 25-candidate pilot. */
final class LeadDiscoveryPilotSummary
{
    public const TARGET = 25;

    /** Candidates that count toward the pilot target after dedupe and geography rejection. */
    public function targetCount(string $tenantId, string $campaignId): int
    {
        return (int) DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
            ->where(function ($query): void {
                $query->whereIn('eligibility_status', ['eligible', 'needs_review'])
                    ->orWhere('validation_reason_code', 'HUMAN_REJECTED');
            })
            ->whereNotIn('processing_status', ['duplicate', 'invalid', 'failed'])
            ->where(function ($query): void {
                $query->whereNull('geographic_relevance')->orWhere('geographic_relevance', '!=', 'location_mismatch');
            })->count();
    }

    public function build(string $tenantId, string $campaignId): array
    {
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenantId)->where('id', $campaignId)->first();
        $campaignConfiguration = $campaign ? (is_array($campaign->configuration) ? $campaign->configuration : (json_decode((string) $campaign->configuration, true) ?: [])) : [];
        $skippedIds = collect((array) data_get($campaignConfiguration, 'pilot_skipped_sources', []))->pluck('source_id')->filter()->unique()->values();
        $skippedSources = $skippedIds->isEmpty() ? collect() : DB::table('lead_discovery_sources')->where('tenant_id', $tenantId)->whereIn('id', $skippedIds)->get(['id', 'source_key', 'provider_name'])->keyBy('id');
        $skipped = collect((array) data_get($campaignConfiguration, 'pilot_skipped_sources', []))->map(function (array $item) use ($skippedSources): array {
            $source = $skippedSources->get($item['source_id'] ?? '');
            return ['source_id' => (string) ($item['source_id'] ?? ''), 'source_key' => (string) ($source->source_key ?? 'unknown'),
                'source' => (string) ($source->provider_name ?? 'Unavailable source'), 'reason_code' => (string) ($item['reason_code'] ?? 'SOURCE_DISABLED')];
        })->values()->all();
        $candidates = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId);
        $runs = DB::table('lead_discovery_runs as r')->join('lead_discovery_sources as s', function ($join) use ($tenantId): void {
            $join->on('s.id', '=', 'r.source_id')->where('s.tenant_id', '=', $tenantId);
        })->where('r.tenant_id', $tenantId)->where('r.campaign_id', $campaignId)
            ->select('r.*', 's.source_key', 's.provider_name')->get();
        $checkpoints = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->get();
        $sources = DB::table('lead_discovery_sources')->where('tenant_id', $tenantId)->whereIn('id', $runs->pluck('source_id')->unique())->get()->keyBy('id');
        $sourceMetrics = $runs->groupBy('source_id')->map(function ($sourceRuns, $sourceId) use ($tenantId, $campaignId, $checkpoints, $sources): array {
            $candidateRows = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->where('source_id', $sourceId);
            $pages = $checkpoints->whereIn('run_id', $sourceRuns->pluck('id'));
            $elapsed = $sourceRuns->sum(function ($run): int {
                if (! $run->started_at) return 0;
                return max(0, (int) \Illuminate\Support\Carbon::parse($run->finished_at ?? now())->diffInSeconds(\Illuminate\Support\Carbon::parse($run->started_at)));
            });
            $source = $sources->get($sourceId);
            return [
                'source_id' => (string) $sourceId, 'source_key' => (string) $sourceRuns->first()->source_key,
                'source' => (string) ($source->provider_name ?? $sourceRuns->first()->provider_name),
                'raw_results' => (int) $sourceRuns->sum('raw_candidates'),
                'staged_candidates' => (int) (clone $candidateRows)->where('processing_status', '!=', 'duplicate')->count(),
                'duplicates' => (int) $sourceRuns->sum('duplicates_found') + (int) (clone $candidateRows)->where('processing_status', 'duplicate')->count(),
                'verified' => (int) (clone $candidateRows)->where('official_domain_status', 'verified')->count(),
                'reviewable' => (int) (clone $candidateRows)->whereIn('eligibility_status', ['eligible', 'needs_review'])->whereNotIn('processing_status', ['failed', 'invalid', 'duplicate'])->count(),
                'rejected' => (int) (clone $candidateRows)->where('eligibility_status', 'rejected')->count(),
                'geographic_mismatches' => (int) (clone $candidateRows)->where('geographic_relevance', 'location_mismatch')->count(),
                'unverifiable_websites' => (int) (clone $candidateRows)->whereIn('validation_reason_code', self::unverifiableCodes())->count(),
                'source_errors' => (int) $sourceRuns->sum('source_errors'),
                'processing_errors' => (int) $sourceRuns->sum('processing_errors'),
                'provider_requests' => (int) $sourceRuns->sum('source_requests'),
                'retries' => (int) $pages->sum(fn ($page) => max(0, (int) $page->attempts - (int) $page->pages_completed)),
                'elapsed_seconds' => $elapsed,
            ];
        })->values()->all();

        $geography = [];
        foreach (['city_match', 'region_match', 'country_match', 'location_unknown', 'location_mismatch'] as $classification) {
            $geography[$classification] = (int) (clone $candidates)->where('geographic_relevance', $classification)->count();
        }
        $verified = (int) (clone $candidates)->where('official_domain_status', 'verified')->count();
        $unverifiable = (int) (clone $candidates)->whereIn('validation_reason_code', self::unverifiableCodes())->count();
        $noWebsite = (int) (clone $candidates)->whereNull('claimed_website')->count();
        $robotsBlocked = (int) (clone $candidates)->whereIn('validation_reason_code', ['ROBOTS', 'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE', 'ROBOTS_DNS_FAILURE', 'ROBOTS_TIMEOUT', 'ROBOTS_CONNECT_TIMEOUT', 'ROBOTS_TLS_FAILURE', 'ROBOTS_CONNECTION_FAILED'])->count();
        $dnsFailure = (int) (clone $candidates)->where('validation_reason_code', 'DNS_FAILURE')->count();
        $timeout = (int) (clone $candidates)->whereIn('validation_reason_code', ['TIMEOUT', 'CONNECT_TIMEOUT'])->count();
        $redirectBlocked = (int) (clone $candidates)->whereIn('validation_reason_code', ['UNSAFE_REDIRECT', 'ROBOTS_UNSAFE_REDIRECT'])->count();
        $connectionUnavailable = (int) (clone $candidates)->whereIn('validation_reason_code', ['HTTP_FAILURE', 'HTTP_UNREACHABLE', 'TLS_FAILURE', 'CONNECTION_FAILED'])->count();
        $reviewable = (int) (clone $candidates)->whereIn('eligibility_status', ['eligible', 'needs_review'])->whereNotIn('processing_status', ['failed', 'invalid', 'duplicate'])->count();
        $runsAll = DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->get(['raw_candidates']);
        $duplicateRows = (int) (clone $candidates)->where('processing_status', 'duplicate')->count();
        $duplicateRuns = (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->sum('duplicates_found');
        $crossSourceMatches = DB::table('lead_discovery_candidate_evidence as e')->join('lead_discovery_candidates as c', function ($join) use ($tenantId, $campaignId): void {
            $join->on('c.id', '=', 'e.candidate_id')->where('c.tenant_id', '=', $tenantId)->where('c.campaign_id', '=', $campaignId);
        })->where('e.tenant_id', $tenantId)->where('e.campaign_id', $campaignId)->select('e.candidate_id')
            ->groupBy('e.candidate_id')->havingRaw('count(distinct e.source_id) > 1')->get()->count();

        return [
            'target' => self::TARGET,
            'target_counting_rule' => 'Count distinct staged candidate records with eligible/needs_review status; exclude duplicates, invalid/internal failures, and location_mismatch. Unverifiable website outcomes remain reviewable and count. Manual rejection does not erase a candidate already staged toward the target.',
            'unique_staged' => $this->targetCount($tenantId, $campaignId),
            'total_raw_candidates' => (int) $runsAll->sum('raw_candidates'),
            'duplicates' => $duplicateRows + $duplicateRuns,
            'verified_candidates' => $verified,
            'unverifiable_candidates' => $unverifiable,
            'no_website' => $noWebsite,
            'eligible_reviewable' => $reviewable,
            'rejected' => (int) (clone $candidates)->where('eligibility_status', 'rejected')->count(),
            'geographic_mismatches' => $geography['location_mismatch'],
            'geographic_distribution' => $geography,
            'verification_distribution' => [
                'verified' => $verified,
                'robots_blocked' => $robotsBlocked,
                'dns_failure' => $dnsFailure,
                'timeout' => $timeout,
                'connection_unavailable' => $connectionUnavailable,
                'redirect_policy_blocked' => $redirectBlocked,
                'no_website' => $noWebsite,
                'other_unverifiable' => max(0, $unverifiable - $robotsBlocked - $dnsFailure - $timeout - $connectionUnavailable - $redirectBlocked - (int) (clone $candidates)->whereIn('validation_reason_code', ['CANDIDATE_WEBSITE_MISSING', 'INVALID_CANDIDATE_URL'])->count()),
            ],
            'human_review' => [
                'not_reviewed' => (int) (clone $candidates)->where('promotion_status', 'not_promoted')->count(),
                'promoted' => (int) (clone $candidates)->where('promotion_status', 'promoted')->count(),
                'manually_rejected' => (int) (clone $candidates)->where('validation_reason_code', 'HUMAN_REJECTED')->count(),
            ],
            'source_contribution' => $sourceMetrics,
            'skipped_sources' => $skipped,
            'deduplication' => [
                'duplicates_by_source' => collect($sourceMetrics)->map(fn (array $source): array => [
                    'source_id' => $source['source_id'], 'source_key' => $source['source_key'], 'duplicates' => $source['duplicates'],
                ])->all(),
                'cross_source_matched_candidates' => (int) $crossSourceMatches,
                'matched_existing_companies' => (int) (clone $candidates)->whereNotNull('matched_company_id')->count(),
                'possible_identity_matches_for_review' => (int) (clone $candidates)->where('validation_reason_code', 'POSSIBLE_CROSS_SOURCE_MATCH')->count(),
                'automatic_merge' => false,
            ],
            'auto_promotion' => false,
            'outreach_created' => false,
        ];
    }

    private static function unverifiableCodes(): array
    {
        return ['ROBOTS', 'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE', 'ROBOTS_DNS_FAILURE', 'ROBOTS_TIMEOUT', 'ROBOTS_CONNECT_TIMEOUT',
            'ROBOTS_TLS_FAILURE', 'ROBOTS_CONNECTION_FAILED', 'ROBOTS_UNSAFE_REDIRECT', 'DNS_FAILURE', 'UNSAFE_URL', 'UNSAFE_REDIRECT',
            'HTTP_FAILURE', 'HTTP_UNREACHABLE', 'NON_HTML', 'OVERSIZED', 'TIMEOUT', 'CONNECT_TIMEOUT', 'TLS_FAILURE', 'CONNECTION_FAILED',
            'CANDIDATE_WEBSITE_MISSING', 'INVALID_CANDIDATE_URL', 'PARSE_FAILURE', 'DIRECTORY_EVIDENCE', 'WEBSITE_IDENTITY_UNCONFIRMED'];
    }
}
