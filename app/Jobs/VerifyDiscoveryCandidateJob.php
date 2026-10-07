<?php

namespace App\Jobs;

use App\Crawling\RobotsRules;
use App\Crawling\UrlPolicy;
use App\Discovery\DomainNormalizer;
use App\Discovery\CheapCandidateFilter;
use App\Discovery\DiscoveryAnalysisBudget;
use App\Discovery\DeterministicCandidateFixtureSourceInterface;
use App\Discovery\DiscoverySourceRegistry;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class VerifyDiscoveryCandidateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 35;
    public int $uniqueFor = 300;
    public string $correlationId;

    public function __construct(public string $tenantId, public string $runId, public string $candidateId)
    {
        $this->correlationId = (string) Str::uuid();
        $this->onQueue('crawl');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->candidateId; }

    public function handle(UrlPolicy $policy, DomainNormalizer $domains, RobotsRules $robots): void
    {
        $candidate = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('discovery_run_id', $this->runId)->where('id', $this->candidateId)->first();
        if (! $candidate || $candidate->verification_state !== 'pending') return;
        try {
            $run = DB::table('discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $this->runId)->first();
            if (! $run || ($run->started_at && now()->diffInSeconds($run->started_at) > (int) config('discovery.max_runtime_seconds', 600))) {
                throw new \RuntimeException('discovery_budget_exceeded');
            }
            $url = $domains->normalize((string) $candidate->original_url)['normalized_url'];
            $source = app(DiscoverySourceRegistry::class)->get((string) $candidate->source);
            if ($source instanceof DeterministicCandidateFixtureSourceInterface) {
                $fixture = $source->fixtureForCandidate((string) $candidate->source_reference);
                if (! $fixture || $fixture['status'] < 200 || $fixture['status'] >= 400) {
                    DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update([
                        'verification_state' => 'unreachable', 'lifecycle_status' => 'verification_failed', 'http_status' => $fixture['status'] ?? null,
                        'failure_code' => 'FIXTURE_UNREACHABLE', 'failure_summary' => 'The fictional acceptance website is marked unreachable.', 'updated_at' => now(),
                    ]);
                    return;
                }
                $html = (string) $fixture['html'];
                $titleMatch = []; $descriptionMatch = []; $descriptionReverseMatch = []; $viewportMatch = []; $canonicalMatch = [];
                preg_match('/<title\\b[^>]*>(.*?)<\\/title>/is', $html, $titleMatch);
                preg_match("/<meta\\b[^>]*name=[\"']description[\"'][^>]*content=[\"']([^\"']*)/is", $html, $descriptionMatch);
                preg_match("/<meta\\b[^>]*content=[\"']([^\"']*)[\"'][^>]*name=[\"']description[\"']/is", $html, $descriptionReverseMatch);
                preg_match("/<meta\\b[^>]*name=[\"']viewport[\"']/is", $html, $viewportMatch);
                preg_match("/<link\\b[^>]*rel=[\"']canonical[\"'][^>]*href=[\"']([^\"']+)/is", $html, $canonicalMatch);
                $this->saveVerification($candidate, $url, $html, $fixture['status'], (int) ($fixture['response_time_ms'] ?? 1), $titleMatch, $descriptionMatch, $descriptionReverseMatch, $viewportMatch, $canonicalMatch, true, $domains);
                return;
            }
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.$candidate->normalized_domain.'/';
            $robotsUrl = rtrim($origin, '/').'/robots.txt';
            $robotsResponse = $policy->fetch($robotsUrl, timeoutSeconds: min(8, (int) config('crawling.request_timeout_seconds', 15)));
            if (! in_array($robotsResponse->status(), [404, 410], true) && ! $robotsResponse->successful()) {
                throw new \RuntimeException('robots_denied');
            }
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            if (! $robots->allows($robotsResponse->body(), $path)) throw new \RuntimeException('robots_denied');
            $requestStarted = microtime(true);
            $response = $policy->fetch($url, timeoutSeconds: min(12, (int) config('crawling.request_timeout_seconds', 15)));
            $responseTime = (int) round((microtime(true) - $requestStarted) * 1000);
            $html = $response->body();
            preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $titleMatch);
            preg_match('/<meta\b[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)/is', $html, $descriptionMatch);
            preg_match('/<meta\b[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/is', $html, $descriptionReverseMatch);
            preg_match('/<meta\b[^>]*name=["\']viewport["\']/is', $html, $viewportMatch);
            preg_match('/<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)/is', $html, $canonicalMatch);
            $canonical = isset($canonicalMatch[1]) ? $canonicalMatch[1] : null;
            if ($canonical) {
                try {
                    $canonicalData = $domains->normalize($canonical);
                    if ($canonicalData['normalized_domain'] !== $candidate->normalized_domain) $canonical = null;
                    else $canonical = $canonicalData['normalized_url'];
                } catch (Throwable) { $canonical = null; }
            }
            $reachable = $response->status() >= 200 && $response->status() < 400;
            $visible = mb_strtolower(html_entity_decode(strip_tags($html)));
            $service = null; $evidence = null;
            if ($reachable && preg_match('/shop|cart|checkout|e-?commerce|online store/', mb_strtolower($html))) {
                $service = 'E-commerce modernization'; $evidence = ['source_url' => $url, 'signal' => 'Public page contains commerce/cart/checkout evidence.'];
            } elseif ($reachable && ! isset($viewportMatch[0])) {
                $service = 'Responsive website improvement'; $evidence = ['source_url' => $url, 'signal' => 'The verified page does not declare a mobile viewport.'];
            } elseif ($reachable && $responseTime >= 1500) {
                $service = 'Performance optimization'; $evidence = ['source_url' => $url, 'signal' => 'Measured first-page response took '.$responseTime.' ms.'];
            } elseif ($reachable && preg_match('/contact us|request a demo|book a call|talk to sales/', $visible)) {
                $service = 'Conversion and CRM automation'; $evidence = ['source_url' => $url, 'signal' => 'Public page contains a business contact or sales conversion path.'];
            }
            $this->saveVerification($candidate, $url, $html, $response->status(), $responseTime, $titleMatch, $descriptionMatch, $descriptionReverseMatch, $viewportMatch, $canonicalMatch, false, $domains);
            if (! $reachable) DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $this->candidateId)->update([
                'verification_state' => $reachable ? 'verified' : 'unreachable', 'lifecycle_status' => $reachable ? 'verified' : 'verification_failed',
                'failure_code' => 'HTTP_UNREACHABLE', 'failure_summary' => 'The public website returned an unsuccessful HTTP response.', 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            $code = match (true) {
                $error->getMessage() === 'robots_denied' => 'ROBOTS_DENIED',
                $error->getMessage() === 'discovery_budget_exceeded' => 'RUN_BUDGET_EXCEEDED',
                str_contains(mb_strtolower($error->getMessage()), 'public address') || str_contains(mb_strtolower($error->getMessage()), 'non-public') => 'UNSAFE_URL',
                default => 'VERIFICATION_FAILED',
            };
            $safeMessage = preg_replace('/https?:\/\/[^\s]+/i', '[public-url]', $error->getMessage()) ?? 'verification error';
            $safeMessage = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '[business-email]', $safeMessage) ?? $safeMessage;
            Log::warning('Discovery candidate website verification failed.', [
                'tenant_id' => $this->tenantId, 'run_id' => $this->runId, 'candidate_id' => $candidate->id,
                'safe_domain' => $candidate->normalized_domain, 'operation' => 'candidate_website_verification',
                'correlation_id' => $this->correlationId,
                'failure_code' => $code, 'exception_type' => $error::class,
                'exception_file' => basename($error->getFile()), 'exception_line' => $error->getLine(),
                'exception_trace' => array_slice(array_map(static fn (array $frame): array => [
                    'file' => isset($frame['file']) ? basename($frame['file']) : null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                ], $error->getTrace()), 0, 8),
                'safe_message' => mb_substr($safeMessage, 0, 300),
            ]);
            DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $this->candidateId)->where('verification_state', 'pending')->update([
                'verification_state' => $code === 'ROBOTS_DENIED' ? 'robots_denied' : 'failed', 'lifecycle_status' => 'verification_failed',
                'failure_code' => $code, 'failure_summary' => $code === 'ROBOTS_DENIED' ? 'The website robots policy disallows this page.' : 'The website could not be safely verified.', 'updated_at' => now(),
            ]);
        } finally {
            $this->refreshRun();
        }
    }

    private function saveVerification(object $candidate, string $url, string $html, int $status, int $responseTime, array $titleMatch, array $descriptionMatch, array $descriptionReverseMatch, array $viewportMatch, array $canonicalMatch, bool $fixture, DomainNormalizer $domains): void
    {
        $canonical = isset($canonicalMatch[1]) ? $canonicalMatch[1] : null;
        if ($canonical) {
            try { $canonicalData = $domains->normalize($canonical); $canonical = $canonicalData['normalized_domain'] === $candidate->normalized_domain ? $canonicalData['normalized_url'] : null; }
            catch (Throwable) { $canonical = null; }
        }
        $reachable = $status >= 200 && $status < 400;
        $visible = mb_strtolower(html_entity_decode(strip_tags($html)));
        $service = null; $evidence = null;
        if ($reachable && preg_match('/shop|cart|checkout|e-?commerce|online store/', mb_strtolower($html))) {
            $service = 'E-commerce modernization'; $evidence = ['source_url' => $url, 'signal' => 'Public page contains commerce/cart/checkout evidence.'];
        } elseif ($reachable && ! isset($viewportMatch[0])) {
            $service = 'Responsive website improvement'; $evidence = ['source_url' => $url, 'signal' => 'The verified page does not declare a mobile viewport.'];
        } elseif ($reachable && preg_match('/contact us|request a demo|book a call|talk to sales/', $visible)) {
            $service = 'Conversion and CRM automation'; $evidence = ['source_url' => $url, 'signal' => 'Public page contains a business contact or sales conversion path.'];
        }
        DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update([
            'verification_state' => $reachable ? 'verified' : 'unreachable', 'lifecycle_status' => $reachable ? 'verified' : 'verification_failed',
            'http_status' => $status, 'canonical_url' => $canonical,
            'page_title' => isset($titleMatch[1]) ? mb_substr(trim(html_entity_decode(strip_tags($titleMatch[1]))), 0, 255) : null,
            'meta_description' => isset($descriptionMatch[1]) ? mb_substr(html_entity_decode($descriptionMatch[1]), 0, 2000) : (isset($descriptionReverseMatch[1]) ? mb_substr(html_entity_decode($descriptionReverseMatch[1]), 0, 2000) : null),
            'has_mobile_viewport' => isset($viewportMatch[0]), 'uses_https' => str_starts_with($url, 'https://'), 'response_time_ms' => $responseTime,
            'recommended_service' => $service, 'recommendation_evidence' => $evidence ? json_encode($evidence) : null,
            'failure_code' => $reachable ? null : 'HTTP_UNREACHABLE', 'failure_summary' => $reachable ? null : 'The public website returned an unsuccessful HTTP response.', 'updated_at' => now(),
        ]);
        if ($reachable) $this->preparePrePromotionCompany($candidate, $url, $html, $status, $canonical, $fixture);
    }

    private function preparePrePromotionCompany(object $candidate, string $url, string $html, int $status, ?string $canonical, bool $fixture): void
    {
        DB::transaction(function () use ($candidate, $url, $html, $status, $canonical, $fixture): void {
            $company = Company::firstOrCreate(['tenant_id' => $this->tenantId, 'normalized_domain' => $candidate->normalized_domain], [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => $candidate->company_name ?: $candidate->normalized_domain,
                'industry' => $candidate->industry, 'location' => trim(($candidate->city ?? '').(($candidate->city && $candidate->country) ? ', ' : '').($candidate->country ?? '')) ?: null,
                'description' => 'Unaccepted company candidate. This record is hidden from active prospect and sales workflows.',
                'source' => 'discovery_candidate', 'status' => 'discovery_candidate',
            ]);
            if ($company->status !== 'discovery_candidate') {
                DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update([
                    'company_id' => $company->id, 'deduplication_state' => 'existing_company', 'deduplication_reason' => 'A company with this normalized domain already exists in this tenant.', 'lifecycle_status' => 'verified', 'updated_at' => now(),
                ]);
                return;
            }
            $websiteId = (string) \Illuminate\Support\Str::uuid();
            DB::table('company_websites')->insertOrIgnore(['id' => $websiteId, 'tenant_id' => $this->tenantId, 'company_id' => $company->id,
                'url' => $url, 'host' => $candidate->normalized_domain, 'canonical_url' => $canonical, 'verification_status' => $fixture ? 'fixture_verified' : 'verified',
                'source' => $candidate->source, 'created_at' => now(), 'updated_at' => now()]);
            $website = DB::table('company_websites')->where('tenant_id', $this->tenantId)->where('company_id', $company->id)->where('host', $candidate->normalized_domain)->first();
            $scan = DB::table('website_scans')->where('tenant_id', $this->tenantId)->where('company_website_id', $website->id)->where('crawler_version', 'discovery-prepromotion-v1')->first();
            $scanId = $scan?->id ?? (string) \Illuminate\Support\Str::uuid();
            if (! $scan) DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $this->tenantId, 'company_website_id' => $website->id,
                'status' => $fixture ? 'completed' : 'queued', 'max_depth' => 2, 'max_pages' => min(10, (int) config('discovery.max_pages_per_domain', 10)),
                'crawler_version' => 'discovery-prepromotion-v1', 'policy_snapshot' => json_encode(['respect_robots' => true, 'source' => $fixture ? 'local_fictional_fixture' : 'discovery_candidate']),
                'started_at' => $fixture ? now() : null, 'finished_at' => $fixture ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            if ($fixture && ! DB::table('website_pages')->where('tenant_id', $this->tenantId)->where('website_scan_id', $scanId)->exists()) {
                DB::table('website_pages')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->tenantId, 'website_scan_id' => $scanId,
                    'requested_url' => $url, 'final_url' => $url, 'canonical_url' => $canonical, 'http_status' => $status, 'content_type' => 'text/html; charset=utf-8',
                    'title' => $candidate->company_name, 'fetched_at' => now(), 'content_hash' => hash('sha256', $html), 'extracted_text' => mb_substr($html, 0, 30000),
                    'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)
                ->update(['company_id' => $company->id, 'lifecycle_status' => 'verified', 'updated_at' => now()]);
            $verified = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->first();
            $filter = app(CheapCandidateFilter::class)->evaluate($verified);
            DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update([
                'eligible_for_analysis' => $filter['eligible'], 'analysis_status' => $filter['eligible'] ? 'eligible' : 'not_eligible',
                'analysis_reason' => $filter['reason'], 'updated_at' => now(),
            ]);
            if ($filter['eligible'] && app(DiscoveryAnalysisBudget::class)->reserve($this->tenantId, $candidate->id)) {
                DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update(['lifecycle_status' => 'analyzing', 'updated_at' => now()]);
                AnalyzeAndScoreDiscoveryCandidateJob::dispatch($this->tenantId, $candidate->id)->afterCommit();
            }
        });
    }

    private function refreshRun(): void
    {
        $counts = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('discovery_run_id', $this->runId)
            ->selectRaw('count(*) as found, sum(case when deduplication_state in (\'existing_company\', \'duplicate_candidate\') then 1 else 0 end) as duplicates, sum(case when verification_state = \'invalid\' then 1 else 0 end) as invalid, sum(case when verification_state = \'not_required\' then 1 else 0 end) as no_website, sum(case when eligible_for_analysis then 1 else 0 end) as eligible_for_analysis, sum(case when verification_state = \'verified\' then 1 else 0 end) as verified, sum(case when lifecycle_status in (\'analyzed\', \'reviewable\', \'accepted\') then 1 else 0 end) as analyzed, sum(case when lifecycle_status in (\'reviewable\', \'accepted\') then 1 else 0 end) as scored, sum(case when verification_state in (\'failed\', \'unreachable\', \'robots_denied\') or lifecycle_status = \'analysis_failed\' then 1 else 0 end) as failures, sum(case when lifecycle_status = \'accepted\' then 1 else 0 end) as accepted, sum(case when lifecycle_status = \'rejected\' then 1 else 0 end) as rejected')
            ->first();
        $pending = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('discovery_run_id', $this->runId)
            ->whereNotIn('deduplication_state', ['existing_company', 'duplicate_candidate'])
            ->where(function ($query): void { $query->where('verification_state', 'pending')->orWhereIn('lifecycle_status', ['verified', 'analyzing', 'analyzed']); })->exists();
        DB::table('discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $this->runId)->update([
            'status' => $pending ? 'analyzing' : 'completed', 'completed_at' => $pending ? null : now(),
            'counts' => json_encode($counts), 'updated_at' => now(),
        ]);
    }
}
