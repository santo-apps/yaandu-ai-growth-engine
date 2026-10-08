<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CandidateDomainDiscoveryService
{
    public function __construct(
        private readonly CandidateDomainDiscoverySourceRegistry $sources,
        private readonly CandidateDomainUrlNormalizer $urls,
        private readonly CandidateDiscoveryRanker $ranker,
    ) {}

    /** @return array{status:string,candidates:int,results:int,queries:int,source_calls:int,source_failures:int,cache_hits:int,latency_ms:int} */
    public function discover(string $tenantId, string $resolutionId): array
    {
        $started = hrtime(true);
        $resolution = DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $resolutionId)->first();
        if (! $resolution) abort(404);
        $identityArray = is_array($resolution->identity_snapshot) ? $resolution->identity_snapshot : (json_decode((string) $resolution->identity_snapshot, true) ?: []);
        $identity = BusinessIdentity::fromArray($identityArray);
        if (! $identity->hasMinimumDiscoveryQuality()) {
            DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $resolutionId)->update([
                'state' => 'NO_CANDIDATES', 'discovery_status' => 'NO_CANDIDATES', 'failure_code' => 'INSUFFICIENT_IDENTITY',
                'failure_summary' => 'Add a business name and useful location or address/category evidence before website discovery.',
                'finished_at' => now(), 'updated_at' => now()]);
            return ['status' => 'NO_CANDIDATES', 'candidates' => 0, 'results' => 0, 'queries' => 0, 'source_calls' => 0, 'source_failures' => 0, 'cache_hits' => 0, 'latency_ms' => 0];
        }

        $maxQueries = min(5, max(1, (int) config('candidate_discovery.max_queries_per_business', 5)));
        $deadline = (int) (hrtime(true) / 1_000_000) + min(55, max(5, (int) config('candidate_discovery.max_runtime_seconds', 45) * 1000));
        $rawLimit = min(10, max(1, (int) config('candidate_discovery.max_results_per_source', 10)));
        $context = new CandidateDiscoveryContext($tenantId, $resolutionId, $maxQueries,
            10, $rawLimit, $deadline);
        DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $resolutionId)->update([
            'state' => 'SEARCHING', 'discovery_status' => 'SEARCHING', 'started_at' => now(), 'failure_code' => null, 'failure_summary' => null, 'updated_at' => now()]);

        $raw = [];
        $sourceCalls = 0;
        $sourceFailures = 0;
        $successfulSources = 0;
        $cacheHits = 0;
        $providerCalls = 0;
        $maxSources = min(8, max(1, (int) config('candidate_discovery.max_sources_per_business', 5)));
        foreach (array_slice($this->sources->all(), 0, $maxSources) as $source) {
            if ((int) (hrtime(true) / 1_000_000) >= $deadline) break;
            $attemptNumber = (int) DB::table('website_resolution_attempts')->where('tenant_id', $tenantId)->where('resolution_id', $resolutionId)
                ->where('source', $source->name())->max('attempt_number') + 1;
            $attemptId = (string) Str::uuid();
            DB::table('website_resolution_attempts')->insert(['id' => $attemptId, 'tenant_id' => $tenantId, 'resolution_id' => $resolutionId,
                'source' => $source->name(), 'attempt_number' => $attemptNumber, 'state' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $sourceCalls++;
            try {
                $batch = $source->discover($identity, $context);
                $sourceFailures += count($batch->failures);
                if ($batch->failures === [] || $batch->results !== []) $successfulSources++;
                $cacheHits += (int) ($batch->metrics['cache_hits'] ?? 0);
                $providerCalls += (int) ($batch->metrics['provider_calls'] ?? 0);
                foreach (array_slice($batch->results, 0, $context->maxRawResultsPerSource) as $result) {
                    $result['source'] = $source->name();
                    $result['attempt_id'] = $attemptId;
                    $raw[] = $result;
                    $this->persistRawResult($tenantId, $resolutionId, $attemptId, $source->name(), $result);
                }
                DB::table('website_resolution_attempts')->where('tenant_id', $tenantId)->where('id', $attemptId)->update([
                    'state' => $batch->failures ? 'partial' : 'completed', 'metrics' => json_encode([
                        'queries' => array_slice($batch->queries, 0, $maxQueries), 'query_count' => min(count($batch->queries), $maxQueries),
                        'raw_results' => count($batch->results), 'cache_hits' => (int) ($batch->metrics['cache_hits'] ?? 0),
                        'provider_calls' => (int) ($batch->metrics['provider_calls'] ?? 0),
                        'source_metrics' => $batch->metrics, 'failure_codes' => array_slice($batch->failures, 0, 10)]),
                    'failure_code' => $batch->failures ? 'PARTIAL_SOURCE_FAILURE' : null,
                    'failure_summary' => $batch->failures ? 'One or more bounded source queries failed; other results were retained.' : null,
                    'finished_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $error) {
                $sourceFailures++;
                DB::table('website_resolution_attempts')->where('tenant_id', $tenantId)->where('id', $attemptId)->update([
                    'state' => 'failed', 'failure_code' => 'SOURCE_UNAVAILABLE', 'failure_summary' => 'Candidate source failed safely.',
                    'metrics' => json_encode(['query_count' => 0, 'raw_results' => 0]), 'finished_at' => now(), 'updated_at' => now()]);
            }
        }

        $ranked = $this->ranker->rank($raw);
        $maxCandidates = min(10, max(1, (int) config('candidate_discovery.max_candidate_domains_per_business', 10)));
        $ranked = array_slice($ranked, 0, $maxCandidates);
        foreach ($ranked as $candidate) $this->persistCandidate($tenantId, $resolutionId, $candidate, $raw);

        $partial = $sourceFailures > 0;
        $candidateCount = count($ranked);
        $status = $candidateCount === 0
            ? ($successfulSources === 0 ? 'FAILED' : ($partial ? 'PARTIALLY_COMPLETED' : 'NO_CANDIDATES'))
            : ($partial ? 'PARTIALLY_COMPLETED' : 'CANDIDATES_FOUND');
        $failureCode = $status === 'FAILED' ? 'ALL_SOURCES_FAILED' : ($partial ? 'PARTIAL_SOURCE_FAILURES' : null);
        $failureSummary = $status === 'FAILED' ? 'All candidate discovery sources failed.' : ($partial ? 'Some sources failed; successful source evidence was retained.' : null);
        DB::table('website_resolutions')->where('tenant_id', $tenantId)->where('id', $resolutionId)->update([
            'state' => $status, 'discovery_status' => $status, 'failure_code' => $failureCode, 'failure_summary' => $failureSummary,
            'discovery_metrics' => json_encode(['source_calls' => $sourceCalls, 'source_failures' => $sourceFailures,
                'raw_results' => count($raw), 'unique_candidates' => $candidateCount, 'queries' => min($maxQueries, count(app(CandidateDomainQueryPlanner::class)->plan($identity))),
                'cache_hits' => $cacheHits, 'provider_calls' => $providerCalls, 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]),
            'finished_at' => $candidateCount === 0 ? now() : null, 'updated_at' => now()]);

        return ['status' => $status, 'candidates' => $candidateCount, 'results' => count($raw), 'queries' => $maxQueries,
            'source_calls' => $sourceCalls, 'source_failures' => $sourceFailures, 'cache_hits' => $cacheHits, 'provider_calls' => $providerCalls,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
    }

    private function persistRawResult(string $tenantId, string $resolutionId, string $attemptId, string $source, array $result): void
    {
        $query = mb_substr((string) ($result['query'] ?? ''), 0, 500);
        $resultUrl = isset($result['result_url']) ? mb_substr((string) $result['result_url'], 0, 2048) : null;
        $targetUrl = isset($result['target_url']) ? mb_substr((string) $result['target_url'], 0, 2048) : null;
        $classifier = app(CandidateDiscoveryResultClassifier::class);
        $resultType = $classifier->classify($resultUrl ?: $targetUrl);
        $targetType = $targetUrl ? $classifier->classify($targetUrl) : null;
        $key = hash('sha256', $source.'|'.$query.'|'.($resultUrl ?? '').'|'.($targetUrl ?? '').'|'.(string) ($result['source_reference'] ?? ''));
        DB::table('website_resolution_search_results')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
            'resolution_id' => $resolutionId, 'attempt_id' => $attemptId, 'source' => $source,
            'query_hash' => hash('sha256', mb_strtolower($query)), 'result_hash' => $key,
            'query_text' => $query ?: null, 'result_url' => $resultUrl, 'target_url' => $targetUrl,
            'title' => mb_substr((string) ($result['title'] ?? ''), 0, 500) ?: null,
            'snippet' => mb_substr((string) ($result['snippet'] ?? ''), 0, 2000) ?: null,
            'source_rank' => isset($result['rank']) ? min(65535, max(1, (int) $result['rank'])) : null,
            'source_reference' => mb_substr((string) ($result['source_reference'] ?? ''), 0, 500) ?: null,
            'result_type' => mb_substr($resultType, 0, 32), 'target_type' => $targetType,
            'metadata' => json_encode((array) ($result['metadata'] ?? [])),
            'retrieved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function persistCandidate(string $tenantId, string $resolutionId, array $candidate, array $raw): void
    {
        $normalized = $this->urls->normalize($candidate['url']);
        $id = (string) Str::uuid();
        DB::table('website_resolution_candidates')->insertOrIgnore(['id' => $id, 'tenant_id' => $tenantId, 'resolution_id' => $resolutionId,
            'normalized_domain' => $normalized['normalized_domain'], 'candidate_url' => $normalized['normalized_url'], 'source' => 'candidate_discovery',
            'candidate_type' => 'business', 'result_type' => $candidate['result_type'], 'discovery_rank' => $candidate['discovery_rank'],
            'discovery_score' => $candidate['discovery_score'], 'status' => 'unverified', 'score' => 0, 'confidence_band' => 'LOW',
            'match_summary' => json_encode(['discovery' => ['rank' => $candidate['discovery_rank'], 'score' => $candidate['discovery_score'],
                'signals' => $candidate['signals'], 'source_count' => $candidate['source_count']]]), 'created_at' => now(), 'updated_at' => now()]);
        $candidateId = (string) DB::table('website_resolution_candidates')->where('tenant_id', $tenantId)->where('resolution_id', $resolutionId)
            ->where('normalized_domain', $normalized['normalized_domain'])->value('id');
        foreach ($candidate['evidence'] as $item) $this->persistEvidence($tenantId, $resolutionId, $candidateId, $item);
        foreach ($raw as $item) {
            $url = (string) ($item['target_url'] ?? '');
            if ($url === '') continue;
            try { $rawDomain = $this->urls->normalize($url)['normalized_domain']; } catch (Throwable) { continue; }
            if ($rawDomain === $normalized['normalized_domain']) {
                $resultKey = hash('sha256', (string) ($item['source'] ?? '').'|'.(string) ($item['query'] ?? '').'|'.(string) ($item['result_url'] ?? '').'|'.(string) ($item['target_url'] ?? '').'|'.(string) ($item['source_reference'] ?? ''));
                DB::table('website_resolution_search_results')->where('tenant_id', $tenantId)->where('resolution_id', $resolutionId)->where('result_hash', $resultKey)->update(['resolution_candidate_id' => $candidateId, 'updated_at' => now()]);
            }
        }
    }

    private function persistEvidence(string $tenantId, string $resolutionId, string $candidateId, array $item): void
    {
        $source = mb_substr((string) ($item['source'] ?? 'candidate_discovery'), 0, 64);
        $signal = mb_substr((string) ($item['signal'] ?? 'candidate_source_evidence'), 0, 48);
        $reference = mb_substr((string) ($item['source_reference'] ?? ''), 0, 500);
        $details = (array) ($item['details'] ?? []);
        $key = hash('sha256', implode('|', [$resolutionId, $candidateId, $source, $signal, $reference, json_encode($details)]));
        DB::table('website_resolution_evidence')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
            'resolution_id' => $resolutionId, 'resolution_candidate_id' => $candidateId, 'source' => $source, 'signal' => $signal,
            'polarity' => in_array($item['polarity'] ?? 'neutral', ['positive', 'negative', 'neutral'], true) ? $item['polarity'] : 'neutral',
            'points' => max(-100, min(100, (int) ($item['points'] ?? 0))), 'evidence_key' => $key, 'source_reference' => $reference ?: null,
            'summary' => mb_substr((string) ($item['summary'] ?? 'Public candidate evidence observed.'), 0, 2000),
            'details' => json_encode($details), 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
