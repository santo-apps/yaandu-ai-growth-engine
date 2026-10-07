<?php

namespace App\WebsiteResolution;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use App\Crawling\UrlPolicy;

final class CommonCrawlClient
{
    public function lookupDomain(string $domain): array
    {
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) return [];
        $crawl = $this->collection();
        $key = 'website-resolution:commoncrawl:'.hash('sha256', strtolower($domain).'|'.$crawl);
        return Cache::remember($key, max(3600, (int) config('website_resolution.common_crawl_cache_seconds', 604800)), function () use ($domain, $crawl): array {
            $url = 'https://index.commoncrawl.org/'.rawurlencode($crawl).'-index';
            $response = $this->request($url, [
                    'url' => strtolower($domain), 'matchType' => 'domain', 'output' => 'json', 'collapse' => 'urlkey',
                    'limit' => min(3, (int) config('website_resolution.common_crawl_records_per_domain', 2)),
                ]);
            if (in_array($response->status(), [404, 410], true)) return [];
            if (! $response->successful() || strlen($response->body()) > 96_000) throw new RuntimeException('Common Crawl index unavailable.');
            $rows = [];
            foreach (preg_split('/\r?\n/', trim($response->body())) as $line) {
                $row = json_decode($line, true);
                if (! is_array($row) || empty($row['url']) || empty($row['timestamp'])) continue;
                $captureHost = strtolower((string) parse_url($row['url'], PHP_URL_HOST));
                if (($captureHost !== strtolower($domain) && ! app(\App\Crawling\PublicSuffixDomainMatcher::class)->sameRegistrableDomain($captureHost, strtolower($domain)))
                    || (int) ($row['status'] ?? 0) !== 200 || ! str_contains(strtolower((string) ($row['mime'] ?? '')), 'html')) continue;
                $filename = (string) ($row['filename'] ?? '');
                $offset = filter_var($row['offset'] ?? null, FILTER_VALIDATE_INT);
                $length = filter_var($row['length'] ?? null, FILTER_VALIDATE_INT);
                if (! preg_match('#^/crawl-data/CC-MAIN-[0-9-]+/segments/[A-Za-z0-9._/-]+\.warc\.gz$#', $filename)
                    || str_contains($filename, '..') || $offset === false || $offset < 0 || $length === false || $length < 1 || $length > (int) config('website_resolution.common_crawl_max_capture_bytes', 1_000_000)) continue;
                $rows[] = ['url' => (string) $row['url'], 'timestamp' => (string) $row['timestamp'], 'mime' => (string) ($row['mime'] ?? ''), 'status' => (int) $row['status'],
                    'filename' => $filename, 'offset' => $offset, 'length' => $length];
            }
            return array_slice($rows, 0, (int) config('website_resolution.common_crawl_records_per_domain', 2));
        });
    }

    public function fetchCapture(array $capture, UrlPolicy $policy): string
    {
        $filename = (string) ($capture['filename'] ?? '');
        $offset = filter_var($capture['offset'] ?? null, FILTER_VALIDATE_INT);
        $length = filter_var($capture['length'] ?? null, FILTER_VALIDATE_INT);
        $limit = min(1_000_000, max(1024, (int) config('website_resolution.common_crawl_max_capture_bytes', 1_000_000)));
        if (! preg_match('#^/crawl-data/CC-MAIN-[0-9-]+/segments/[A-Za-z0-9._/-]+\.warc\.gz$#', $filename)
            || str_contains($filename, '..') || $offset === false || $offset < 0 || $length === false || $length < 1 || $length > $limit || $offset > PHP_INT_MAX - $length) {
            throw new RuntimeException('Common Crawl capture metadata was rejected.');
        }
        $end = $offset + $length - 1;
        $response = $policy->fetch('https://data.commoncrawl.org'.$filename, timeoutSeconds: 20, requestHeaders: ['Range' => "bytes={$offset}-{$end}"]);
        if ($response->status() !== 206 || strlen($response->body()) > $limit) throw new RuntimeException('Common Crawl capture response was unavailable or oversized.');
        $record = @gzdecode($response->body(), $limit);
        if (! is_string($record) || strlen($record) > $limit) throw new RuntimeException('Common Crawl WARC record could not be decoded within its limit.');
        $warcHeaderEnd = strpos($record, "\r\n\r\n");
        if ($warcHeaderEnd === false) throw new RuntimeException('Common Crawl WARC record header was invalid.');
        $payload = substr($record, $warcHeaderEnd + 4);
        $httpHeaderEnd = strpos($payload, "\r\n\r\n");
        if ($httpHeaderEnd === false) throw new RuntimeException('Common Crawl HTTP payload was invalid.');
        $httpHeaders = substr($payload, 0, $httpHeaderEnd);
        if (! preg_match('/^HTTP\/\d(?:\.\d)?\s+2\d\d\b/m', $httpHeaders)
            || ! preg_match('/^content-type:\s*text\/html\b/im', $httpHeaders)) throw new RuntimeException('Common Crawl payload is not a successful HTML page.');
        return substr($payload, $httpHeaderEnd + 4, $limit);
    }

    private function collection(): string
    {
        $configured = trim((string) config('website_resolution.common_crawl_collection', ''));
        if ($configured !== '') return $configured;
        return Cache::remember('website-resolution:commoncrawl:collections', 86400, function (): string {
            $response = $this->request('https://index.commoncrawl.org/collinfo.json');
            if (! $response->successful() || strlen($response->body()) > 256_000) throw new RuntimeException('Common Crawl collection list unavailable.');
            $collections = $response->json();
            $id = is_array($collections) ? (string) ($collections[0]['id'] ?? '') : '';
            if (! preg_match('/^CC-MAIN-[0-9]{4}-[0-9]{2}$/', $id)) throw new RuntimeException('Common Crawl collection list was invalid.');
            return $id;
        });
    }

    private function request(string $url, array $query = []): \Illuminate\Http\Client\Response
    {
        $lock = Cache::lock('website-resolution:commoncrawl:request-lock', 35);
        if (! $lock->get()) throw new RuntimeException('Common Crawl source is rate limited; retry later.');
        try {
            $intervalMs = max(1000, (int) config('website_resolution.common_crawl_min_interval_ms', 2000));
            $lastRequest = (int) Cache::get('website-resolution:commoncrawl:last-request-ms', 0);
            $remainingMs = $intervalMs - ((int) floor(microtime(true) * 1000) - $lastRequest);
            if ($lastRequest > 0 && $remainingMs > 0) usleep($remainingMs * 1000);
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                Cache::put('website-resolution:commoncrawl:last-request-ms', (int) floor(microtime(true) * 1000), 3600);
                $response = Http::acceptJson()->withHeaders(['User-Agent' => (string) config('website_resolution.user_agent')])
                    ->withOptions(['stream' => true])->connectTimeout(3)->timeout(10)->get($url, $query);
                $stream = $response->toPsrResponse()->getBody();
                $body = '';
                while (! $stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') break;
                    if (strlen($body) + strlen($chunk) > 256_000) {
                        $stream->close();
                        throw new RuntimeException('Common Crawl response exceeded the configured limit.');
                    }
                    $body .= $chunk;
                }
                $response = new Response($response->toPsrResponse()->withBody(Utils::streamFor($body)));
                if (! in_array($response->status(), [429, 502, 503, 504], true) || $attempt === 2) return $response;
                usleep(500_000);
            }
        } finally { $lock->release(); }
        throw new RuntimeException('Common Crawl source unavailable.');
    }
}
