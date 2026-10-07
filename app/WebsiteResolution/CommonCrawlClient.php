<?php

namespace App\WebsiteResolution;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
                if (strtolower((string) parse_url($row['url'], PHP_URL_HOST)) !== strtolower($domain)
                    || (int) ($row['status'] ?? 0) !== 200 || ! str_contains(strtolower((string) ($row['mime'] ?? '')), 'html')) continue;
                $rows[] = ['url' => (string) $row['url'], 'timestamp' => (string) $row['timestamp'], 'mime' => (string) ($row['mime'] ?? ''), 'status' => (int) ($row['status'] ?? 0)];
            }
            return array_slice($rows, 0, (int) config('website_resolution.common_crawl_records_per_domain', 2));
        });
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
