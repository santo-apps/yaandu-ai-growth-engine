<?php

namespace Tests\Unit;

use App\WebsiteResolution\CommonCrawlClient;
use App\WebsiteResolution\WikidataClient;
use App\WebsiteResolution\WikidataWebsiteResolutionSource;
use App\Crawling\PublicAddressResolverInterface;
use App\Crawling\UrlPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommonCrawlClientTest extends TestCase
{
    public function test_lookup_is_bounded_parsed_domain_exactly_and_cached(): void
    {
        Cache::flush();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/collinfo.json')) return Http::response([['id' => 'CC-MAIN-2025-43']], 200);
            return Http::response(implode("\n", [
                json_encode(['url' => 'https://example.com/', 'timestamp' => '20251014220259', 'status' => '200', 'mime' => 'text/html', 'filename' => '/crawl-data/CC-MAIN-2025-43/segments/1/warc/one.warc.gz', 'offset' => '100', 'length' => '500']),
                json_encode(['url' => 'https://www.example.com/', 'timestamp' => '20251014220259', 'status' => '200', 'mime' => 'text/html', 'filename' => '/crawl-data/CC-MAIN-2025-43/segments/1/warc/two.warc.gz', 'offset' => '100', 'length' => '500']),
                json_encode(['url' => 'https://example.com/about', 'timestamp' => '20251014220300', 'status' => '404', 'mime' => 'text/html', 'filename' => '/crawl-data/CC-MAIN-2025-43/segments/1/warc/three.warc.gz', 'offset' => '100', 'length' => '500']),
            ]), 200);
        });
        config(['website_resolution.common_crawl_min_interval_ms' => 1000]);

        $client = app(CommonCrawlClient::class);
        $rows = $client->lookupDomain('example.com');
        self::assertCount(2, $rows);
        self::assertSame('https://example.com/', $rows[0]['url']);
        self::assertSame(100, $rows[0]['offset']);
        self::assertSame('https://www.example.com/', $rows[1]['url']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/collinfo.json'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'CC-MAIN-2025-43-index'));
        self::assertSame($rows, $client->lookupDomain('EXAMPLE.COM'));
        self::assertCount(2, Http::recorded());
    }

    public function test_invalid_domains_do_not_make_common_crawl_requests(): void
    {
        Http::fake();
        self::assertSame([], app(CommonCrawlClient::class)->lookupDomain('localhost'));
        self::assertCount(0, Http::recorded());
    }

    public function test_bounded_warc_capture_uses_validated_range_and_extracts_only_html_payload(): void
    {
        $record = "WARC/1.0\r\nWARC-Type: response\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><title>Captured Business</title></html>";
        Http::fake(['https://data.commoncrawl.org/*' => Http::response(gzencode($record), 206)]);
        $addresses = new class implements PublicAddressResolverInterface { public function resolve(string $host): array { return ['93.184.216.34']; } };
        $capture = ['filename' => '/crawl-data/CC-MAIN-2025-43/segments/1/warc/sample.warc.gz', 'offset' => 100, 'length' => 200,
            'url' => 'https://known.example/', 'timestamp' => '20251014220259'];

        $html = app(CommonCrawlClient::class)->fetchCapture($capture, new UrlPolicy($addresses));

        self::assertSame('<html><title>Captured Business</title></html>', $html);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://data.commoncrawl.org/crawl-data/')
            && $request->hasHeader('Range', 'bytes=100-299'));
    }

    public function test_invalid_warc_paths_are_rejected_before_network_access(): void
    {
        Http::fake();
        $addresses = new class implements PublicAddressResolverInterface { public function resolve(string $host): array { return ['93.184.216.34']; } };
        try {
            app(CommonCrawlClient::class)->fetchCapture(['filename' => '/crawl-data/../../etc/passwd.warc.gz', 'offset' => 0, 'length' => 10], new UrlPolicy($addresses));
            self::fail('Unsafe WARC paths must be rejected.');
        } catch (\RuntimeException $error) { self::assertStringContainsString('metadata was rejected', $error->getMessage()); }
        self::assertCount(0, Http::recorded());
    }

    public function test_wikidata_brand_reference_is_weaker_than_direct_business_reference(): void
    {
        Cache::flush();
        Http::fake(['https://www.wikidata.org/*' => Http::response(['entities' => [
            'Q123' => ['claims' => ['P856' => [['mainsnak' => ['datavalue' => ['value' => 'https://brand.example']]]]]],
            'Q456' => ['claims' => ['P856' => [['mainsnak' => ['datavalue' => ['value' => 'https://business.example']]]]]],
        ]], 200)]);
        $source = new WikidataWebsiteResolutionSource(app(WikidataClient::class));

        $brand = $source->find(['brand_wikidata_id' => 'Q123']);
        $direct = $source->find(['wikidata_id' => 'Q456']);

        self::assertSame('brand_reference', $brand[0]['candidate_type']);
        self::assertSame(30, $brand[0]['evidence'][0]['points']);
        self::assertSame('official_reference', $direct[0]['candidate_type']);
        self::assertSame(85, $direct[0]['evidence'][0]['points']);
    }
}
