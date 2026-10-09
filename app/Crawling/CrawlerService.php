<?php

namespace App\Crawling;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\WebsiteIntelligence\PlaywrightScreenshotService;
use App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy;
use RuntimeException;

final class CrawlerService
{
    public function __construct(private readonly UrlPolicy $policy, private readonly RobotsRules $robots, private readonly SitemapParser $sitemaps, private readonly UrlResolver $resolver, private readonly PlaywrightScreenshotService $screenshots) {}

    public function crawl(string $tenantId, string $websiteId, int $maxPages = 30, int $maxDepth = 2, ?string $existingScanId = null, ?int $maxBrowserRenders = null): string
    {
        $website = DB::table('company_websites')->where('tenant_id', $tenantId)->where('id', $websiteId)->first();
        if (! $website) throw new RuntimeException('Website not found in this tenant.');
        $maxPages = min(100, max(1, $maxPages)); $maxDepth = min(5, max(0, $maxDepth));
        $maxAttempts = min(1000, max(1, (int) config('crawling.max_fetch_attempts', 100)));
        $maxLinks = min(500, max(1, (int) config('crawling.max_links_per_page', 200)));
        $maxDuration = min(240, max(1, (int) config('crawling.max_duration_seconds', 180)));
        $policySnapshot = ['user_agent' => 'YaanduGrowthBot', 'respect_robots' => true,
            'max_fetch_attempts' => $maxAttempts, 'max_links_per_page' => $maxLinks, 'max_duration_seconds' => $maxDuration];
        $scanId = $existingScanId ?? (string) Str::uuid();
        if ($existingScanId) {
            $existing = DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->where('company_website_id', $websiteId)->first();
            if (! $existing) throw new RuntimeException('Scan not found in this tenant.');
            if ($existing->status === 'completed') return $scanId;
            $maxPages = min(100, max(1, (int) $existing->max_pages)); $maxDepth = min(5, max(0, (int) $existing->max_depth));
            $snapshot = is_array($existing->policy_snapshot) ? $existing->policy_snapshot : (json_decode($existing->policy_snapshot ?? '{}', true) ?: []);
            $maxAttempts = min($maxAttempts, max(1, (int) ($snapshot['max_fetch_attempts'] ?? $maxAttempts)));
            $maxLinks = min($maxLinks, max(1, (int) ($snapshot['max_links_per_page'] ?? $maxLinks)));
            $maxDuration = min($maxDuration, max(1, (int) ($snapshot['max_duration_seconds'] ?? $maxDuration)));
            $policySnapshot = [...$snapshot, 'user_agent' => 'YaanduGrowthBot', 'respect_robots' => true,
                'max_fetch_attempts' => $maxAttempts, 'max_links_per_page' => $maxLinks, 'max_duration_seconds' => $maxDuration];
            DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->update([
                'status' => 'running', 'error_code' => null, 'error_summary' => null, 'failure_category' => null,
                'retryable' => 'UNKNOWN', 'safe_error_summary' => null, 'finished_at' => null,
                'policy_snapshot' => json_encode($policySnapshot), 'started_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenantId, 'company_website_id' => $websiteId,
                'status' => 'running', 'max_depth' => $maxDepth, 'max_pages' => $maxPages, 'crawler_version' => 'http-v1',
                'policy_snapshot' => json_encode($policySnapshot), 'original_scan_id' => $scanId,
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        if (app()->environment(['local', 'testing']) && config('pilot.allow_simulated_fixtures', false)
            && str_ends_with(strtolower((string) parse_url($website->url, PHP_URL_HOST)), '.fixture.test')) {
            return $this->crawlSimulatedPilotFixture($tenantId, $website, $scanId);
        }
        $budget = new CrawlBudget($maxAttempts, $maxDuration);
        try {
            $root = rtrim($website->url, '/');
            $robotsText = $this->fetchRobotsRules($root.'/robots.txt', $budget);
            $queue = [[$root, 0]];
            foreach ($this->sitemaps->urls($this->safeFetchBody($root.'/sitemap.xml', $budget), $maxPages) as $url) $queue[] = [$url, 1];
        $seen = []; $host = strtolower($website->host); $saved = 0; $browserRenders = 0;
            while ($queue && $saved < $maxPages) {
                $budget->assertTime();
                [$url, $depth] = array_shift($queue);
                $normalized = $this->normalize($url);
                if (isset($seen[$normalized]) || $depth > $maxDepth) continue;
                [$targetHost] = $this->policy->validatePublicHttpUrl($normalized);
                if ($targetHost !== $host || ! $this->robots->allows($robotsText, parse_url($normalized, PHP_URL_PATH) ?: '/')) continue;
                $seen[$normalized] = true;
                $response = $this->fetchWithRetry($normalized, $budget);
                if (! $response->successful()) throw new RuntimeException('Website returned HTTP '.$response->status().'.');
                $contentType = strtolower((string) $response->header('Content-Type'));
                if (! str_contains($contentType, 'text/html') && ($contentType !== '' || ! preg_match('/^\s*</', $response->body()))) {
                    if ($depth === 0) throw new RuntimeException('Website root returned unsupported content type.');
                    continue;
                }
                $html = $response->body(); $finalUrl = $normalized;
                $canonical = $this->canonical($html, $finalUrl);
                $text = $this->extractText($html);
                $existingPage = DB::table('website_pages')->where('tenant_id', $tenantId)
                    ->where('website_scan_id', $scanId)->where('requested_url', $normalized)->first(['id', 'created_at']);
                $pageId = $existingPage?->id ?? (string) Str::uuid();
                $objectKey = "tenants/{$tenantId}/crawls/{$scanId}/{$pageId}.html";
                Storage::disk(config('filesystems.default'))->put($objectKey, $html);
                DB::table('website_pages')->updateOrInsert(['id' => $pageId], ['tenant_id' => $tenantId, 'website_scan_id' => $scanId,
                    'requested_url' => $normalized, 'final_url' => $finalUrl, 'canonical_url' => $canonical,
                    'http_status' => $response->status(), 'content_type' => $contentType,
                    'title' => $this->title($html), 'fetched_at' => now(), 'content_hash' => hash('sha256', $html), 'object_key' => $objectKey,
                    'extracted_text' => mb_substr($text, 0, 30000), 'depth' => $depth, 'created_at' => $existingPage?->created_at ?? now(), 'updated_at' => now()]);
                if ($depth === 0 && ($maxBrowserRenders === null || $browserRenders < $maxBrowserRenders)) {
                    $browserRenders++;
                    $renderedHtml = $this->screenshots->capture($tenantId, $scanId, $pageId, $finalUrl, $budget->remainingSeconds());
                    $budget->assertTime();
                    if ($renderedHtml !== null && mb_strlen($text) < 200) {
                        $renderedText = $this->extractText($renderedHtml);
                        if (mb_strlen($renderedText) > mb_strlen($text)) {
                            Storage::disk(config('filesystems.default'))->put($objectKey, $renderedHtml);
                            DB::table('website_pages')->where('tenant_id', $tenantId)->where('id', $pageId)->update([
                                'content_hash' => hash('sha256', $renderedHtml), 'title' => $this->title($renderedHtml),
                                'extracted_text' => mb_substr($renderedText, 0, 30000), 'updated_at' => now(),
                            ]);
                        }
                    }
                }
                $saved++;
                if ($depth < $maxDepth) foreach ($this->links($html, $finalUrl, $maxLinks) as $link) if (! isset($seen[$this->normalize($link)])) $queue[] = [$link, $depth + 1];
            }
            DB::table('website_scans')->where('id', $scanId)->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            return $scanId;
        } catch (\Throwable $e) {
            $taxonomy = app(WebsiteIntelligenceFailureTaxonomy::class);
            $category = $taxonomy->classify($e);
            $summary = $taxonomy->safeSummary($category);
            DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->update([
                'status' => 'failed', 'error_code' => $category, 'failure_category' => $category,
                'retryable' => $taxonomy->retryability($category), 'safe_error_summary' => $summary,
                'error_summary' => $summary, 'original_scan_id' => $scanId,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    private function crawlSimulatedPilotFixture(string $tenantId, object $website, string $scanId): string
    {
        $company = DB::table('companies')->where('tenant_id', $tenantId)->where('id', $website->company_id)->first(['name']);
        $html = '<!doctype html><html><head><title>SIMULATED ONLY · '.e((string) ($company->name ?? 'Fictional business')).'</title></head><body>'
            .'<main><h1>SIMULATED ONLY: fictional retail ecommerce business</h1>'
            .'<p>Fixture observation: older storefront layout. Mobile navigation is difficult. '
            .'This local evidence is synthetic and contains no real performance or customer measurements.</p>'
            .'<a href="/contact">Contact</a></main></body></html>';
        $pageId = (string) Str::uuid();
        $objectKey = "tenants/{$tenantId}/crawls/{$scanId}/{$pageId}.html";
        Storage::disk(config('filesystems.default'))->put($objectKey, $html);
        DB::table('website_pages')->insert(['id' => $pageId, 'tenant_id' => $tenantId, 'website_scan_id' => $scanId,
            'requested_url' => $website->url, 'final_url' => $website->url, 'canonical_url' => $website->url, 'http_status' => 200,
            'content_type' => 'text/html', 'title' => 'SIMULATED ONLY · '.mb_substr((string) ($company->name ?? 'Fictional business'), 0, 400),
            'fetched_at' => now(), 'content_hash' => hash('sha256', $html), 'object_key' => $objectKey,
            'extracted_text' => mb_substr($this->extractText($html), 0, 30000), 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->update([
            'status' => 'completed', 'crawler_version' => 'pilot-simulated-fixture-v1',
            'policy_snapshot' => json_encode(['simulated_fixture' => true, 'network_requests' => 0, 'robots_fetch' => 'not_applicable_fixture']),
            'finished_at' => now(), 'updated_at' => now(),
        ]);
        return $scanId;
    }

    private function safeFetchBody(string $url, CrawlBudget $budget): string
    {
        try { return $this->policy->fetch($url, $budget, $budget->remainingSeconds())->body(); } catch (\Throwable) { return ''; }
    }

    private function fetchRobotsRules(string $url, CrawlBudget $budget): string
    {
        try {
            $response = $this->policy->fetch($url, $budget, $budget->remainingSeconds());
        } catch (\Throwable $error) {
            throw new RuntimeException('Unable to verify robots.txt; crawling stopped.', previous: $error);
        }

        if ($response->status() === 404 || $response->status() === 410) return '';
        if (! $response->successful()) throw new RuntimeException('robots.txt could not be retrieved; crawling stopped.');

        return $response->body();
    }

    private function fetchWithRetry(string $url, CrawlBudget $budget): \Illuminate\Http\Client\Response
    {
        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $budget->assertTime();
            try { return $this->policy->fetch($url, $budget, $budget->remainingSeconds()); } catch (\Throwable $e) { $last = $e; if ($attempt < 2) usleep((int) min(250_000 * (2 ** $attempt), max(0, ($budget->remainingSeconds() - 1) * 1_000_000))); }
        }
        throw $last ?? new RuntimeException('Crawl request failed.');
    }

    private function normalize(string $url): string
    {
        $parts = parse_url($url); if (! $parts) return $url;
        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function canonical(string $html, string $base): ?string
    {
        if (! preg_match('/<link\b(?=[^>]*\brel=["\']canonical["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $html, $m)) return null;
        try { return $this->resolver->resolve($base, $m[1]); } catch (\Throwable) { return null; }
    }

    private function title(string $html): ?string { return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? mb_substr(trim(html_entity_decode(strip_tags($m[1]))), 0, 500) : null; }
    private function extractText(string $html): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html))))); }
    private function links(string $html, string $base, int $maxLinks): array
    {
        $links = [];
        $offset = 0;
        $length = strlen($html);
        while (count($links) < $maxLinks && $offset < $length
            && preg_match('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $href = $match[1][0];
            $offset = $match[0][1] + strlen($match[0][0]);
            if (str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) continue;
            try { $links[] = $this->resolver->resolve($base, $href); } catch (\Throwable) {}
        }
        return array_slice(array_values(array_unique($links)), 0, $maxLinks);
    }
}
