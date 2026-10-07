<?php

namespace App\WebsiteResolution;

use App\Crawling\UrlPolicy;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CommonCrawlIngestionSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['domains_considered' => 0, 'cdx_captures' => 0, 'records_fetched' => 0, 'documents_indexed' => 0,
        'duplicates' => 0, 'bytes_processed' => 0, 'source_failures' => 0, 'robots_denied' => 0,
        'byte_budget_exhausted' => 0, 'time_budget_exhausted' => 0, 'domain_budget_exhausted' => 0];

    public function __construct(private readonly CommonCrawlClient $client, private readonly UrlPolicy $policy, private readonly PublicWebsiteDocumentFetcher $fetcher) {}

    public function name(): string { return 'common_crawl_known_domains'; }
    public function metrics(): array { return $this->counts; }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $limit = min(500, max(1, $limit));
        $maxDomains = min(50, max(1, (int) ($options['max_domains'] ?? $limit)));
        $maxBytes = min(50_000_000, max(1024, (int) ($options['max_bytes'] ?? config('website_resolution.common_crawl_max_run_bytes', 10_000_000))));
        $deadline = microtime(true) + min(300, max(10, (int) ($options['max_runtime_seconds'] ?? 180)));
        $bytes = 0; $lastDomain = (string) ($cursor['last_domain'] ?? '');
        $documents = DB::table('web_index_documents')->select('normalized_domain', 'canonical_url', 'organization_name', 'city', 'country', 'business_category')
            ->where('document_type', 'business')->where('availability', 'available')->distinct()->orderBy('normalized_domain')->limit($maxDomains * 4)->get();
        foreach ($documents as $known) {
            if ($lastDomain !== '' && strcmp((string) $known->normalized_domain, $lastDomain) <= 0) continue;
            if ($this->counts['documents_indexed'] >= $limit) break;
            if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break; }
            if ($this->counts['domains_considered'] >= $maxDomains) { $this->counts['domain_budget_exhausted']++; break; }
            if ($bytes >= $maxBytes) { $this->counts['byte_budget_exhausted']++; break; }
            $this->counts['domains_considered']++;
            try { $captures = $this->client->lookupDomain((string) $known->normalized_domain); }
            catch (Throwable) { $this->counts['source_failures']++; continue; }
            foreach (array_slice($captures, 0, min(2, (int) config('website_resolution.common_crawl_records_per_domain', 1))) as $capture) {
                $this->counts['cdx_captures']++;
                $length = (int) ($capture['length'] ?? 0);
                if ($length < 1) continue;
                if ($bytes + $length > $maxBytes) { $this->counts['byte_budget_exhausted']++; break 2; }
                if (microtime(true) >= $deadline) { $this->counts['time_budget_exhausted']++; break 2; }
                try {
                    $html = $this->client->fetchCapture($capture, $this->policy);
                    $bytes += $length; $this->counts['bytes_processed'] += $length; $this->counts['records_fetched']++;
                    $document = $this->fetcher->extractArchived((string) $capture['url'], $html,
                        ['organization_name' => $known->organization_name, 'city' => $known->city, 'country' => $known->country]);
                    $document += ['business_category' => $known->business_category, 'source' => $this->name(), 'source_reference' => 'commoncrawl:'.$capture['timestamp'].':'.$capture['filename'].':'.$capture['offset'],
                        'source_timestamp' => $this->captureTime((string) $capture['timestamp']), 'evidence_type' => 'historical_capture',
                        'source_query' => ['known_domain' => $known->normalized_domain, 'capture_url' => $capture['url'], 'mime' => $capture['mime']],
                        '_cursor' => ['last_domain' => $known->normalized_domain]];
                    $this->counts['documents_indexed']++;
                    yield $document;
                } catch (\RuntimeException $error) {
                    if ($error->getMessage() === 'ROBOTS_DENIED') $this->counts['robots_denied']++;
                    else $this->counts['source_failures']++;
                } catch (Throwable) { $this->counts['source_failures']++; }
            }
        }
    }

    private function captureTime(string $timestamp): ?string
    {
        if (! preg_match('/^\d{14}$/', $timestamp)) return null;
        $date = \DateTimeImmutable::createFromFormat('!YmdHis', $timestamp);
        return $date?->format(DATE_ATOM);
    }
}
