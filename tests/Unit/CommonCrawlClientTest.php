<?php

namespace Tests\Unit;

use App\WebsiteResolution\CommonCrawlClient;
use App\WebsiteResolution\WikidataClient;
use App\WebsiteResolution\WikidataWebsiteResolutionSource;
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
                json_encode(['url' => 'https://example.com/', 'timestamp' => '20251014220259', 'status' => '200', 'mime' => 'text/html']),
                json_encode(['url' => 'https://www.example.com/', 'timestamp' => '20251014220259', 'status' => '200', 'mime' => 'text/html']),
                json_encode(['url' => 'https://example.com/about', 'timestamp' => '20251014220300', 'status' => '404', 'mime' => 'text/html']),
            ]), 200);
        });
        config(['website_resolution.common_crawl_min_interval_ms' => 1000]);

        $client = app(CommonCrawlClient::class);
        $rows = $client->lookupDomain('example.com');
        self::assertCount(1, $rows);
        self::assertSame('https://example.com/', $rows[0]['url']);
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
