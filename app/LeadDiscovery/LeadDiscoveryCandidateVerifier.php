<?php

namespace App\LeadDiscovery;

use App\WebsiteResolution\PublicWebsiteDocumentFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class LeadDiscoveryCandidateVerifier
{
    public function __construct(
        private readonly PublicWebsiteDocumentFetcher $fetcher,
        private readonly DiscoveryEligibilityService $eligibility,
    ) {}

    public function verify(string $tenantId, string $candidateId): void
    {
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->first();
        if (! $candidate || in_array($candidate->promotion_status, ['promoted', 'rejected'], true)) return;
        if (($candidate->geographic_relevance ?? null) === 'location_mismatch') {
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                'processing_status' => 'rejected', 'validation_status' => 'rejected', 'eligibility_status' => 'rejected',
                'validation_reason_code' => 'GEOGRAPHIC_LOCATION_MISMATCH',
                'validation_reason' => 'Reliable source location evidence conflicts with the campaign geography.', 'updated_at' => now(),
            ]);
            $this->refreshRun($tenantId, (string) $candidate->run_id);
            return;
        }
        $source = DB::table('lead_discovery_sources')->where('tenant_id', $tenantId)->where('id', $candidate->source_id)->first();
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenantId)->where('id', $candidate->campaign_id)->first();
        if (! $source || ! $campaign) return;
        $run = DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('id', $candidate->run_id)->first();
        if (! $run || in_array($run->run_status, ['cancelled', 'failed', 'paused'], true)
            || now()->diffInSeconds($run->started_at) > (int) data_get(json_decode((string) $campaign->configuration, true), 'limits.runtime_seconds', config('lead_discovery.max_runtime_seconds'))) return;
        if (! $source->enabled || $source->authorization_status !== 'authorized') {
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                'processing_status' => 'rejected', 'validation_status' => 'rejected', 'eligibility_status' => 'rejected',
                'validation_reason_code' => 'SOURCE_NOT_AUTHORIZED', 'validation_reason' => 'The selected source is no longer enabled and authorized.', 'updated_at' => now(),
            ]);
            $this->refreshRun($tenantId, (string) $candidate->run_id);
            return;
        }
        DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)
            ->update(['processing_status' => 'validating', 'updated_at' => now()]);

        try {
            if (! $candidate->claimed_website) throw new \InvalidArgumentException('CANDIDATE_WEBSITE_MISSING');
            $campaignConfig = is_array($campaign->configuration) ? $campaign->configuration : (json_decode((string) $campaign->configuration, true) ?: []);
            $maximumFetches = min(10, max(2, (int) data_get($campaignConfig, 'limits.http_requests', config('lead_discovery.max_http_requests_per_candidate'))));
            $document = $this->fetcher->fetch($candidate->claimed_website, ['organization_name' => $candidate->company_name], max(0, min(2, $maximumFetches - 2)));
            $officialName = trim((string) ($document['organization_name'] ?? $document['page_title'] ?? ''));
            $similarity = $this->nameSimilarity($candidate->normalized_name, $officialName);
            $verified = $officialName !== '' && $similarity >= 0.65;
            $facts = [
                'status_code' => 'WEBSITE_IDENTITY_MATCH',
                'page_title' => mb_substr((string) ($document['page_title'] ?? ''), 0, 255),
                'observed_business_name' => mb_substr($officialName, 0, 255),
                'identity_similarity' => round($similarity, 4),
                'https' => str_starts_with((string) $document['canonical_url'], 'https://'),
            ];
            $evidence = ['source_id' => $source->id, 'campaign_id' => $campaign->id, 'source_url' => $document['canonical_url'] ?? $candidate->claimed_website,
                'collected_at' => now()->toIso8601String(), 'authorization_status' => $source->authorization_status];
            $this->storeEvidence($tenantId, $candidate, 'official_domain', 'verified_website', (string) $document['canonical_url'], (string) $document['normalized_domain'], $verified ? 0.95 : 0.4, $evidence);
            if ($officialName !== '') $this->storeEvidence($tenantId, $candidate, 'company_name', 'website_observation', $officialName, $officialName, min(0.99, max(0.1, $similarity)), $evidence);
            if (! empty($document['industry'])) $this->storeEvidence($tenantId, $candidate, 'industry', 'website_observation', (string) $document['industry'], (string) $document['industry'], 0.6, $evidence);

            $emails = array_values(array_unique(array_filter((array) ($document['email_values'] ?? []), fn ($email) => $this->isGenericBusinessEmail((string) $email, (string) $candidate->normalized_domain))));
            foreach (array_slice($emails, 0, 5) as $email) $this->storeEvidence($tenantId, $candidate, 'business_email', 'public_business_contact', $email, mb_strtolower($email), 0.8, $evidence);
            foreach (array_slice(array_values(array_unique((array) ($document['phone_values'] ?? []))), 0, 3) as $phone) {
                if (is_string($phone) && strlen($phone) <= 50) $this->storeEvidence($tenantId, $candidate, 'business_phone', 'public_business_contact', $phone, $phone, 0.75, $evidence);
            }

            $candidateData = (array) $candidate;
            $candidateData['official_domain_status'] = $verified ? 'verified' : 'needs_review';
            $candidateData['country_code'] = strtoupper((string) $candidate->country_code);
            $candidateData['geographic_relevance'] = $candidate->geographic_relevance ?? 'location_unknown';
            $candidateData['duplicate_status'] = $candidate->matched_company_id ? 'duplicate' : 'new';
            $decision = $this->eligibility->evaluate($candidateData, (array) $campaign, (array) $source);
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                'official_domain_status' => $verified ? 'verified' : 'needs_review',
                'identity_confidence' => round($similarity, 4), 'website_confidence' => $verified ? 0.95 : 0.4,
                'eligibility_confidence' => $decision['confidence'], 'eligibility_status' => $decision['status'],
                'validation_status' => $verified ? 'verified' : 'needs_review',
                'processing_status' => $decision['status'] === 'eligible' ? 'eligible' : 'needs_review',
                'validation_reason_code' => $verified ? $decision['reason_code'] : 'WEBSITE_IDENTITY_UNCONFIRMED',
                'validation_reason' => $verified ? $decision['reason'] : 'The public website identity could not be confidently matched to the candidate business name.',
                'verification_facts' => json_encode($facts), 'verified_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            $code = $this->failureCode($error);
            $candidateFailure = $this->isCandidateWebsiteFailure($code);
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                'processing_status' => $candidateFailure ? 'needs_review' : 'failed',
                'validation_status' => $candidateFailure ? 'needs_review' : 'failed',
                'official_domain_status' => $candidateFailure ? 'needs_review' : $candidate->official_domain_status,
                'eligibility_status' => 'needs_review', 'eligibility_confidence' => null,
                'validation_reason_code' => $code, 'validation_reason' => $this->safeReason($code), 'updated_at' => now(),
            ]);
            Log::warning($candidateFailure ? 'Lead discovery candidate website could not be verified.' : 'Lead discovery candidate processing failed safely.', [
                'operation' => $candidateFailure ? 'lead_discovery_candidate_unverifiable' : 'lead_discovery_processing_error',
                'tenant_id' => $tenantId, 'run_id' => $candidate->run_id, 'candidate_id' => $candidateId,
                'safe_domain' => $candidate->normalized_domain, 'failure_code' => $code,
                'exception_class' => $error::class, 'exception_file' => basename($error->getFile()), 'exception_line' => $error->getLine(),
            ]);
            report_if(app()->environment('local', 'testing'), $error);
        } finally {
            $this->refreshRun($tenantId, (string) $candidate->run_id);
        }
    }

    private function refreshRun(string $tenantId, string $runId): void
    {
        $rows = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('run_id', $runId);
        $counts = (clone $rows)->selectRaw("count(*) as candidates_found, sum(case when official_domain_status = 'verified' then 1 else 0 end) as verified_candidates, sum(case when eligibility_status = 'eligible' then 1 else 0 end) as eligible_candidates, sum(case when promotion_status = 'promoted' then 1 else 0 end) as promoted_candidates, sum(case when eligibility_status = 'rejected' then 1 else 0 end) as rejected_candidates, sum(case when processing_status = 'failed' then 1 else 0 end) as processing_errors")->first();
        $pending = (clone $rows)->whereIn('processing_status', ['discovered', 'validating'])->exists();
        $sourcePending = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenantId)->where('run_id', $runId)->whereIn('status', ['queued', 'running'])->exists();
        $pending = $pending || $sourcePending;
        DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('id', $runId)->update([
            'candidates_found' => $counts->candidates_found ?? 0, 'verified_candidates' => $counts->verified_candidates ?? 0,
            'eligible_candidates' => $counts->eligible_candidates ?? 0, 'promoted_candidates' => $counts->promoted_candidates ?? 0,
            'rejected_candidates' => $counts->rejected_candidates ?? 0, 'processing_errors' => $counts->processing_errors ?? 0,
            'run_status' => $pending ? 'running' : (($counts->processing_errors ?? 0) > 0 ? 'completed_with_errors' : 'completed'),
            'finished_at' => $pending ? null : now(), 'updated_at' => now(),
        ]);
        if (! $pending) {
            $campaignId = DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('id', $runId)->value('campaign_id');
            $otherPending = DB::table('lead_discovery_runs')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
                ->whereIn('run_status', ['queued', 'running', 'paused'])->exists();
            if (! $otherPending) DB::table('lead_discovery_campaigns')->where('tenant_id', $tenantId)->where('id', $campaignId)
                ->whereNotIn('status', ['paused', 'cancelled'])->update(['status' => ($counts->processing_errors ?? 0) > 0 ? 'completed_with_errors' : 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        }
    }

    private function storeEvidence(string $tenantId, object $candidate, string $field, string $type, ?string $raw, ?string $normalized, float $confidence, array $attribution): void
    {
        DB::table('lead_discovery_candidate_evidence')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'candidate_id' => $candidate->id,
            'campaign_id' => $candidate->campaign_id, 'source_id' => $candidate->source_id,
            'field_name' => $field, 'evidence_type' => $type, 'source_url' => $attribution['source_url'],
            'raw_value' => $raw === null ? null : mb_substr($raw, 0, 2000), 'normalized_value' => $normalized === null ? null : mb_substr($normalized, 0, 2000),
            'confidence_score' => max(0, min(1, $confidence)), 'collected_at' => now(), 'attribution' => json_encode($attribution),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nameSimilarity(string $left, string $right): float
    {
        $normalize = static fn (string $value): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value)) ?: '';
        $left = $normalize($left); $right = $normalize($right);
        if ($left === '' || $right === '') return 0.0;
        if ($left === $right || str_contains($left, $right) || str_contains($right, $left)) return 1.0;
        similar_text($left, $right, $percent);
        return $percent / 100;
    }

    private function isGenericBusinessEmail(string $email, string $domain): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        [$local, $host] = explode('@', mb_strtolower($email), 2);
        return $host === mb_strtolower($domain) && in_array($local, ['info', 'sales', 'contact', 'enquiry', 'enquiries', 'hello', 'support'], true);
    }

    private function failureCode(Throwable $error): string
    {
        $message = mb_strtoupper(trim($error->getMessage()));
        $codes = ['ROBOTS', 'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE', 'ROBOTS_DNS_FAILURE', 'ROBOTS_TIMEOUT', 'ROBOTS_CONNECT_TIMEOUT',
            'ROBOTS_TLS_FAILURE', 'ROBOTS_CONNECTION_FAILED', 'ROBOTS_UNSAFE_REDIRECT', 'DNS_FAILURE', 'UNSAFE_URL',
            'UNSAFE_REDIRECT', 'HTTP_FAILURE', 'HTTP_UNREACHABLE', 'NON_HTML', 'OVERSIZED', 'TIMEOUT', 'CONNECT_TIMEOUT',
            'TLS_FAILURE', 'CONNECTION_FAILED', 'TRANSPORT_FAILURE', 'CANDIDATE_WEBSITE_MISSING', 'PARSE_FAILURE', 'DIRECTORY_EVIDENCE'];
        if (in_array($message, $codes, true)) {
            if ($message === 'TRANSPORT_FAILURE') return $this->transportFailureCode($error);
            if ($message === 'ROBOTS_TRANSPORT_FAILURE') return 'ROBOTS_UNAVAILABLE';
            return $message;
        }
        if ($message === 'ROBOTS_TRANSPORT_FAILURE'
            || (str_starts_with($message, 'ROBOTS_') && in_array(substr($message, 7), ['DNS_FAILURE', 'TIMEOUT', 'CONNECT_TIMEOUT', 'TLS_FAILURE', 'CONNECTION_FAILED', 'TRANSPORT_FAILURE'], true))) {
            return 'ROBOTS_UNAVAILABLE';
        }

        return 'VERIFICATION_FAILED';
    }

    private function transportFailureCode(Throwable $error): string
    {
        for ($current = $error->getPrevious(); $current; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage());
            if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) return 'TLS_FAILURE';
            if (str_contains($message, 'timeout') && str_contains($message, 'connect')) return 'CONNECT_TIMEOUT';
            if (str_contains($message, 'timeout')) return 'TIMEOUT';
        }

        return 'CONNECTION_FAILED';
    }

    private function isCandidateWebsiteFailure(string $code): bool
    {
        return in_array($code, ['ROBOTS', 'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE', 'ROBOTS_DNS_FAILURE', 'ROBOTS_TIMEOUT',
            'ROBOTS_CONNECT_TIMEOUT', 'ROBOTS_TLS_FAILURE', 'ROBOTS_CONNECTION_FAILED', 'ROBOTS_UNSAFE_REDIRECT',
            'DNS_FAILURE', 'UNSAFE_URL', 'UNSAFE_REDIRECT', 'HTTP_FAILURE', 'HTTP_UNREACHABLE', 'NON_HTML', 'OVERSIZED',
            'TIMEOUT', 'CONNECT_TIMEOUT', 'TLS_FAILURE', 'CONNECTION_FAILED', 'CANDIDATE_WEBSITE_MISSING', 'INVALID_CANDIDATE_URL',
            'PARSE_FAILURE', 'DIRECTORY_EVIDENCE'], true);
    }

    private function safeReason(string $code): string
    {
        return match ($code) {
            'ROBOTS', 'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE', 'ROBOTS_DNS_FAILURE', 'ROBOTS_TIMEOUT', 'ROBOTS_CONNECT_TIMEOUT',
            'ROBOTS_TLS_FAILURE', 'ROBOTS_CONNECTION_FAILED' => 'Website crawl permission could not be verified.',
            'UNSAFE_URL', 'UNSAFE_REDIRECT', 'ROBOTS_UNSAFE_REDIRECT', 'INVALID_CANDIDATE_URL' => 'The website URL did not pass public URL safety checks.',
            'DNS_FAILURE' => 'The website host could not be resolved safely.',
            'HTTP_FAILURE', 'HTTP_UNREACHABLE', 'CONNECTION_FAILED', 'TLS_FAILURE' => 'The public website could not be reached safely.',
            'NON_HTML' => 'The public website response was not HTML.',
            'OVERSIZED' => 'The website response exceeded the configured size limit.',
            'TIMEOUT', 'CONNECT_TIMEOUT' => 'The website response exceeded the configured timeout.',
            'CANDIDATE_WEBSITE_MISSING' => 'No public website was provided for this candidate.',
            'PARSE_FAILURE', 'DIRECTORY_EVIDENCE' => 'The public website could not be verified as an official business website.',
            default => 'The website could not be verified safely.',
        };
    }
}
