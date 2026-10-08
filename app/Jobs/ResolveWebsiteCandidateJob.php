<?php

namespace App\Jobs;

use App\Crawling\RobotsRules;
use App\Crawling\UrlPolicy;
use App\Discovery\DomainNormalizer;
use App\Models\WebsiteResolution;
use App\WebsiteResolution\BusinessWebsiteIdentityMatcher;
use App\WebsiteResolution\DirectoryDomainClassifier;
use App\WebsiteResolution\WebsiteIdentityPageExtractor;
use App\WebsiteResolution\WebsiteResolutionSourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class ResolveWebsiteCandidateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 70;
    public int $uniqueFor = 900;

    public function __construct(public string $tenantId, public string $resolutionId) { $this->onQueue('crawl'); }
    public function uniqueId(): string { return $this->tenantId.':'.$this->resolutionId; }
    public function middleware(): array { return [(new WithoutOverlapping('website-resolution-global'))->releaseAfter(5)->expireAfter(90)]; }

    public function failed(Throwable $error): void
    {
        DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)
            ->whereIn('state', ['PENDING', 'SEARCHING', 'CANDIDATES_FOUND', 'VERIFYING'])
            ->update(['state' => 'FAILED', 'failure_code' => 'JOB_EXECUTION_FAILED',
                'discovery_status' => 'FAILED', 'failure_summary' => 'Website resolution stopped unexpectedly. It can be retried safely.', 'finished_at' => now(), 'updated_at' => now()]);
    }

    public function handle(WebsiteResolutionSourceRegistry $sources, DomainNormalizer $domains, UrlPolicy $policy,
        RobotsRules $robots, WebsiteIdentityPageExtractor $extractor, BusinessWebsiteIdentityMatcher $matcher,
        DirectoryDomainClassifier $directory): void
    {
        $resolution = DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->first();
        if (! $resolution || ! in_array($resolution->state, ['PENDING', 'FAILED', 'CANDIDATES_FOUND', 'PARTIALLY_COMPLETED'], true)) return;
        $preDiscovered = in_array($resolution->state, ['CANDIDATES_FOUND', 'PARTIALLY_COMPLETED'], true)
            && ($resolution->discovery_status ?? 'PENDING') !== 'PENDING';
        $discoveryWasPartial = ($resolution->discovery_status ?? null) === 'PARTIALLY_COMPLETED';
        $snapshot = is_array($resolution->identity_snapshot) ? $resolution->identity_snapshot : (json_decode((string) $resolution->identity_snapshot, true) ?: []);
        DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->update([
            'state' => $preDiscovered ? 'VERIFYING' : 'SEARCHING', 'discovery_status' => $preDiscovered ? 'VERIFYING' : $resolution->discovery_status,
            'started_at' => now(), 'failure_code' => null, 'failure_summary' => null, 'updated_at' => now()]);
        $allCandidates = []; $failures = []; $lookupCount = 0;
        $sourceNames = $preDiscovered ? [] : ['osm_website_evidence', 'wikidata', 'local_web_index'];
        if (app()->environment('testing')) $sourceNames[] = 'deterministic';
        $deadline = microtime(true) + min(60, max(1, (int) config('website_resolution.max_duration_seconds', 60)));
        foreach ($sourceNames as $sourceName) {
            if ($lookupCount >= (int) config('website_resolution.max_source_lookups_per_business', 3)) break;
            if (microtime(true) >= $deadline) { $failures['budget'] = 'Resolution time budget reached.'; break; }
            $lookupCount++;
            $attemptNumber = (int) DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('resolution_id', $this->resolutionId)->where('source', $sourceName)->max('attempt_number') + 1;
            $attemptId = (string) Str::uuid(); $started = now();
            DB::table('website_resolution_attempts')->insert(['id' => $attemptId, 'tenant_id' => $this->tenantId, 'resolution_id' => $this->resolutionId,
                'source' => $sourceName, 'attempt_number' => $attemptNumber, 'state' => 'running', 'started_at' => $started,
                'created_at' => now(), 'updated_at' => now()]);
            try {
                $found = $sources->get($sourceName)->find($snapshot, $allCandidates);
                foreach ($found as $candidate) $this->mergeHint($allCandidates, $candidate);
                DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('id', $attemptId)->update([
                    'state' => 'completed', 'metrics' => json_encode(['candidate_hints' => count($found)]), 'finished_at' => now(), 'updated_at' => now()]);
                foreach ($found as $candidate) if (empty($candidate['url'])) $this->saveEvidence(null, (string) ($candidate['source'] ?? $sourceName), (array) ($candidate['evidence'] ?? []), (string) ($candidate['source_reference'] ?? ''));
            } catch (Throwable $error) {
                $failures[$sourceName] = $this->safeFailure($error);
                DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('id', $attemptId)->update([
                    'state' => 'failed', 'failure_code' => 'SOURCE_UNAVAILABLE', 'failure_summary' => $failures[$sourceName], 'finished_at' => now(), 'updated_at' => now()]);
            }
        }
        $stored = []; $invalidCandidateObserved = false;
        foreach (array_slice($allCandidates, 0, (int) config('website_resolution.max_candidate_domains_per_business', 5)) as $hint) {
            if (empty($hint['url'])) continue;
            try { $normalized = $domains->normalize((string) $hint['url']); }
            catch (Throwable) { $invalidCandidateObserved = true; $this->saveEvidence(null, (string) ($hint['source'] ?? 'unknown'), [['signal' => 'invalid_candidate_url', 'polarity' => 'negative', 'points' => 0, 'summary' => 'Candidate URL failed safe URL normalization.']], (string) ($hint['source_reference'] ?? '')); continue; }
            $id = $this->storeCandidate($normalized['normalized_domain'], $normalized['normalized_url'], $hint);
            $stored[$id] = ['id' => $id, 'url' => $normalized['normalized_url'], 'normalized_domain' => $normalized['normalized_domain'], 'candidate_type' => $hint['candidate_type'] ?? 'business'];
            $this->saveEvidence($id, (string) ($hint['source'] ?? 'unknown'), (array) ($hint['evidence'] ?? []), (string) ($hint['source_reference'] ?? ''));
        }
        if ($preDiscovered) {
            foreach (DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('resolution_id', $this->resolutionId)
                ->orderByRaw('discovery_rank is null, discovery_rank asc')->limit((int) config('candidate_discovery.verification_candidates_per_business', 5))->get() as $candidate) {
                $stored[$candidate->id] = ['id' => $candidate->id, 'url' => $candidate->candidate_url,
                    'normalized_domain' => $candidate->normalized_domain, 'candidate_type' => $candidate->candidate_type];
            }
        }

        if ($lookupCount < (int) config('website_resolution.max_source_lookups_per_business', 3) && $stored && microtime(true) < $deadline && ! app()->environment('testing')) {
            $lookupCount++;
            $attemptNumber = (int) DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('resolution_id', $this->resolutionId)->where('source', 'common_crawl')->max('attempt_number') + 1;
            $attemptId = (string) Str::uuid();
            DB::table('website_resolution_attempts')->insert(['id' => $attemptId, 'tenant_id' => $this->tenantId, 'resolution_id' => $this->resolutionId,
                'source' => 'common_crawl', 'attempt_number' => $attemptNumber, 'state' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            try {
                $ccRows = $sources->get('common_crawl')->find($snapshot, array_values($stored));
                foreach ($ccRows as $row) {
                    $matched = collect($stored)->firstWhere('normalized_domain', $row['existing_domain'] ?? null);
                    $id = is_array($matched) ? ($matched['id'] ?? null) : null;
                    if ($id) $this->saveEvidence($id, 'common_crawl', (array) ($row['evidence'] ?? []), (string) ($row['source_reference'] ?? ''));
                }
                DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('id', $attemptId)->update([
                    'state' => 'completed', 'metrics' => json_encode(['captured_domains' => count($ccRows)]), 'finished_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $error) {
                $failures['common_crawl'] = $this->safeFailure($error);
                DB::table('website_resolution_attempts')->where('tenant_id', $this->tenantId)->where('id', $attemptId)->update([
                    'state' => 'failed', 'failure_code' => 'SOURCE_UNAVAILABLE', 'failure_summary' => $failures['common_crawl'], 'finished_at' => now(), 'updated_at' => now()]);
            }
        }
        $rows = DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('resolution_id', $this->resolutionId)
            ->when($preDiscovered, fn ($query) => $query->orderByRaw('discovery_rank is null, discovery_rank asc')->limit((int) config('candidate_discovery.verification_candidates_per_business', 5)))
            ->get();
        if ($rows->isNotEmpty()) DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->update(['state' => 'CANDIDATES_FOUND', 'updated_at' => now()]);
        $results = [];
        if ($rows->isNotEmpty()) {
            DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->update(['state' => 'VERIFYING', 'updated_at' => now()]);
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    $this->saveEvidence($row->id, 'resolution_budget', [['signal' => 'resolution_time_budget', 'polarity' => 'neutral', 'points' => 0,
                        'summary' => 'Candidate verification was skipped because the resolution time budget was reached.']], 'budget');
                    $results[] = ['id' => $row->id, 'domain' => $row->normalized_domain, 'url' => $row->candidate_url,
                        'status' => 'failed', 'score' => 0, 'confidence_band' => 'LOW', 'positive_evidence' => [], 'negative_evidence' => [], 'evidence' => [], 'site' => []];
                    continue;
                }
                $results[] = $this->validateAndScore($row, $snapshot, $domains, $policy, $robots, $extractor, $matcher, $directory);
            }
        }
        $ranked = collect($results)->sortByDesc('score')->values();
        $strong = $ranked->filter(fn ($item) => $item['score'] >= (int) config('website_resolution.medium_confidence_threshold', 50) && $item['status'] === 'proposed');
        $top = $ranked->first();
        $state = 'UNRESOLVED'; $failureCode = null; $failureSummary = null; $resolvedCandidateId = null; $resolvedDomain = null;
        if ($strong->isNotEmpty()) {
            if ($strong->count() === 1 && $strong->first()['confidence_band'] === 'HIGH' && config('website_resolution.auto_resolve_high_confidence', false)) {
                $state = 'RESOLVED'; $resolvedCandidateId = $strong->first()['id']; $resolvedDomain = $strong->first()['domain'];
                $this->attachAndVerify($resolvedCandidateId, $resolvedDomain, $strong->first()['url']);
            } else $state = 'AMBIGUOUS';
        } elseif ($results && $ranked->every(fn ($item) => $item['status'] === 'failed')) {
            $state = 'FAILED'; $failureCode = 'NETWORK_FAILURE'; $failureSummary = 'Candidate websites could not be safely reached or checked within the configured budget.';
        } elseif ($failures && ! $stored) {
            $state = 'FAILED'; $failureCode = 'SOURCE_UNAVAILABLE'; $failureSummary = implode('; ', array_values($failures));
        } elseif ($rows->isNotEmpty() && $ranked->every(fn ($item) => $item['status'] === 'rejected')) {
            $failureCode = 'CANDIDATES_REJECTED'; $failureSummary = 'Candidate websites were rejected by safety or identity checks.';
        } elseif ($rows->isEmpty()) {
            $failureCode = $failures ? 'SOURCE_UNAVAILABLE' : ($invalidCandidateObserved ? 'CANDIDATES_REJECTED' : 'NO_CANDIDATES');
            $failureSummary = $failures ? implode('; ', array_values($failures)) : ($invalidCandidateObserved ? 'Candidate URLs were rejected by safe URL normalization.' : 'No candidate website evidence was available.');
        }
        DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->update([
            'state' => $state, 'resolved_candidate_id' => $resolvedCandidateId, 'resolved_domain' => $resolvedDomain,
            'discovery_status' => $discoveryWasPartial ? 'PARTIALLY_COMPLETED' : ($preDiscovered ? 'COMPLETED' : $resolution->discovery_status),
            'score' => $top['score'] ?? null, 'confidence_band' => $top['confidence_band'] ?? null,
            'failure_code' => $failureCode, 'failure_summary' => $failureSummary, 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function validateAndScore(object $candidate, array $identity, DomainNormalizer $domains, UrlPolicy $policy, RobotsRules $robots,
        WebsiteIdentityPageExtractor $extractor, BusinessWebsiteIdentityMatcher $matcher, DirectoryDomainClassifier $directory): array
    {
        $evidenceRows = DB::table('website_resolution_evidence')->where('tenant_id', $this->tenantId)->where('resolution_candidate_id', $candidate->id)->get();
        $sourceEvidence = $evidenceRows->map(fn ($row) => ['signal' => $row->signal, 'polarity' => $row->polarity, 'points' => (int) $row->points,
            'summary' => $row->summary, 'source' => $row->source, 'details' => json_decode((string) $row->details, true) ?: []])->all();
        $kind = app(DirectoryDomainClassifier::class)->classify($candidate->candidate_url);
        if ($kind) {
            $sourceEvidence[] = ['signal' => $kind, 'polarity' => 'negative', 'points' => -100, 'summary' => 'Directory and social-profile pages cannot be assigned as official company websites.', 'source' => 'policy', 'details' => []];
            DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update(['status' => 'rejected', 'updated_at' => now()]);
            $match = $matcher->evaluate($identity, [], $sourceEvidence, $candidate->normalized_domain);
            $this->saveMatch($candidate->id, $match, 'rejected');
            return ['id' => $candidate->id, 'domain' => $candidate->normalized_domain, 'url' => $candidate->candidate_url, 'status' => 'rejected', ...$match];
        }
        try {
            $policy->validatePublicHttpUrl($candidate->candidate_url);
            $robotsUrl = 'https://'.$candidate->normalized_domain.'/robots.txt';
            $robotsResponse = $policy->fetch($robotsUrl, timeoutSeconds: 5);
            if (! in_array($robotsResponse->status(), [404, 410], true) && ! $robotsResponse->successful()) throw new \RuntimeException('Robots policy could not be checked.');
            if (! $robots->allows($robotsResponse->body(), (string) (parse_url($candidate->candidate_url, PHP_URL_PATH) ?: '/'))) throw new \RuntimeException('Robots policy denied the candidate page.');
            $site = Cache::remember('website-resolution:page:'.hash('sha256', strtolower($candidate->candidate_url)),
                max(300, (int) config('website_resolution.candidate_page_cache_seconds', 86400)), function () use ($policy, $candidate, $extractor): array {
                    $response = $policy->fetch($candidate->candidate_url, timeoutSeconds: 8);
                    if (! $response->successful() || strlen($response->body()) > (int) config('website_resolution.max_candidate_page_bytes', 1_000_000)) throw new \RuntimeException('Candidate website could not be safely read.');
                    return $extractor->extract($response->body(), $candidate->candidate_url);
                });
            $match = $matcher->evaluate($identity, $site, $sourceEvidence, $candidate->normalized_domain);
            $status = $match['confidence_band'] === 'LOW' ? 'rejected' : 'proposed';
            $this->saveMatch($candidate->id, $match, $status);
            return ['id' => $candidate->id, 'domain' => $candidate->normalized_domain, 'url' => $candidate->candidate_url, 'status' => $status, ...$match];
        } catch (Throwable $error) {
            $unsafe = str_contains(mb_strtolower($error->getMessage()), 'public address') || str_contains(mb_strtolower($error->getMessage()), 'public http') || str_contains(mb_strtolower($error->getMessage()), 'hostname');
            $sourceEvidence[] = ['signal' => $unsafe ? 'unsafe_url' : 'candidate_fetch_failed', 'polarity' => 'negative', 'points' => $unsafe ? -100 : 0,
                'summary' => $unsafe ? 'Candidate URL failed the existing public URL/SSRF policy.' : 'Candidate website could not be verified; this failure does not imply an identity mismatch.',
                'source' => 'url_policy', 'details' => []];
            $match = $matcher->evaluate($identity, [], $sourceEvidence, $candidate->normalized_domain);
            $this->saveMatch($candidate->id, $match, $unsafe ? 'rejected' : 'failed');
            return ['id' => $candidate->id, 'domain' => $candidate->normalized_domain, 'url' => $candidate->candidate_url, 'status' => $unsafe ? 'rejected' : 'failed', ...$match];
        }
    }

    private function storeCandidate(string $domain, string $url, array $hint): string
    {
        $id = (string) Str::uuid();
        DB::table('website_resolution_candidates')->insertOrIgnore(['id' => $id, 'tenant_id' => $this->tenantId, 'resolution_id' => $this->resolutionId,
            'normalized_domain' => $domain, 'candidate_url' => $url, 'source' => mb_substr((string) ($hint['source'] ?? 'unknown'), 0, 64),
            'candidate_type' => mb_substr((string) ($hint['candidate_type'] ?? 'business'), 0, 32), 'status' => 'proposed', 'score' => 0, 'confidence_band' => 'LOW',
            'created_at' => now(), 'updated_at' => now()]);
        return (string) (DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('resolution_id', $this->resolutionId)->where('normalized_domain', $domain)->value('id') ?? $id);
    }

    private function saveEvidence(?string $candidateId, string $source, array $evidence, string $reference): void
    {
        foreach ($evidence as $item) {
            $evidenceSource = mb_substr((string) ($item['source'] ?? $source), 0, 64);
            $signal = mb_substr((string) ($item['signal'] ?? 'source_evidence'), 0, 48);
            $evidenceReference = (string) ($item['source_reference'] ?? $reference);
            $key = hash('sha256', implode('|', [$this->resolutionId, $candidateId ?? '', $evidenceSource, $signal, $evidenceReference, json_encode($item['details'] ?? [])]));
            DB::table('website_resolution_evidence')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId,
                'resolution_id' => $this->resolutionId, 'resolution_candidate_id' => $candidateId, 'source' => $evidenceSource,
                'signal' => $signal, 'polarity' => in_array($item['polarity'] ?? 'positive', ['positive', 'negative', 'neutral'], true) ? $item['polarity'] : 'neutral',
                'points' => max(-100, min(100, (int) ($item['points'] ?? 0))), 'evidence_key' => $key, 'source_reference' => $evidenceReference ?: null,
                'summary' => mb_substr((string) ($item['summary'] ?? 'Public identity evidence observed.'), 0, 2000),
                'details' => json_encode($item['details'] ?? []), 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function saveMatch(string $candidateId, array $match, string $status): void
    {
        DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidateId)->update([
            'status' => $status, 'score' => $match['score'], 'confidence_band' => $match['confidence_band'],
            'match_summary' => json_encode(['positive_evidence' => $match['positive_evidence'], 'negative_evidence' => $match['negative_evidence'], 'site' => $match['site']]), 'updated_at' => now()]);
        $sourceEvidence = array_filter($match['evidence'], fn ($item) => $item['source'] === 'matcher');
        $this->saveEvidence($candidateId, 'business_identity_matcher', $sourceEvidence, 'matcher:'.$candidateId);
    }

    private function attachAndVerify(string $resolutionCandidateId, string $domain, string $url): void
    {
        $resolution = DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->first();
        DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $resolution->candidate_id)->update([
            'original_url' => $url, 'normalized_domain' => $domain, 'verification_state' => 'pending', 'lifecycle_status' => 'discovered', 'updated_at' => now()]);
        VerifyDiscoveryCandidateJob::dispatch($this->tenantId, $resolution->discovery_run_id, $resolution->candidate_id)->afterCommit();
        DB::table('website_resolution_candidates')->where('tenant_id', $this->tenantId)->where('id', $resolutionCandidateId)->update(['status' => 'confirmed', 'updated_at' => now()]);
    }

    private function mergeHint(array &$target, array $hint): void
    {
        foreach ((array) ($hint['evidence'] ?? []) as &$item) {
            $item['source'] ??= (string) ($hint['source'] ?? 'unknown');
            $item['source_reference'] ??= (string) ($hint['source_reference'] ?? '');
        }
        unset($item);
        if (empty($hint['url'])) { $target[] = $hint; return; }
        $domain = null;
        try { $domain = app(DomainNormalizer::class)->normalize((string) $hint['url'])['normalized_domain']; } catch (Throwable) {}
        foreach ($target as $index => $existing) {
            if ($domain && ! empty($existing['url'])) try { if (app(DomainNormalizer::class)->normalize((string) $existing['url'])['normalized_domain'] === $domain) {
                $target[$index]['evidence'] = [...(array) ($existing['evidence'] ?? []), ...(array) ($hint['evidence'] ?? [])]; return;
            } } catch (Throwable) {}
        }
        $target[] = $hint;
    }

    private function safeFailure(Throwable $error): string
    {
        $message = preg_replace('/https?:\/\/[^\s]+/i', '[external-url]', $error->getMessage()) ?? 'Resolution source failed.';
        return mb_substr(preg_replace('/[\r\n\t]+/', ' ', $message) ?? 'Resolution source failed.', 0, 240);
    }
}
