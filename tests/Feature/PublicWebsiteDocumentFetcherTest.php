<?php

namespace Tests\Feature;

use App\Crawling\PublicAddressResolverInterface;
use App\WebsiteResolution\PublicWebsiteDocumentFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicWebsiteDocumentFetcherTest extends TestCase
{
    public function test_fetcher_uses_at_most_two_safe_identity_pages_and_preserves_page_evidence(): void
    {
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $hostKey = 'crawl-host:'.hash('sha256', 'identity-example.com');
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($hostKey) {
            RateLimiter::clear($hostKey);
            $url = $request->url();
            if ($url === 'https://identity-example.com/robots.txt') return Http::response('', 404);
            if ($url === 'https://identity-example.com/') return Http::response(<<<'HTML'
                <title>Welcome</title>
                <a href="/contact">Contact Us</a>
                <a href="/about">About Us</a>
                <a href="https://attacker.example.net/about">About the parent company</a>
                HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            if ($url === 'https://identity-example.com/contact') return Http::response(<<<'HTML'
                <script type="application/ld+json">{"@type":"Organization","name":"Harbor Works","telephone":"+91 484 555 0100","email":"hello@identity-example.com","address":{"@type":"PostalAddress","streetAddress":"12 Market Road","addressLocality":"Kochi","addressCountry":"India"}}</script>
                HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            if ($url === 'https://identity-example.com/about') return Http::response(<<<'HTML'
                <script type="application/ld+json">{"@type":"Organization","name":"Harbor Works","legalName":"Harbor Works Private Limited","url":"https://identity-example.com/"}</script>
                HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            return Http::response('', 404);
        });

        $document = app(PublicWebsiteDocumentFetcher::class)->fetch('https://identity-example.com/');

        self::assertSame('Harbor Works', $document['organization_name']);
        self::assertSame('Kochi', $document['city']);
        self::assertSame('India', $document['country']);
        self::assertSame(['+91 484 555 0100'], $document['phone_values']);
        self::assertSame(['hello@identity-example.com'], $document['email_values']);
        self::assertCount(3, $document['identity_pages']);
        self::assertSame(['homepage', 'contact', 'about'], array_column($document['identity_pages'], 'type'));
        self::assertSame(3, $document['_fetch_count']);
        self::assertGreaterThan(0, $document['_bytes']);
        self::assertCount(2, $document['structured_data']['entities']);
        self::assertSame(0, Http::recorded()->filter(fn ($request) => str_contains($request[0]->url(), 'attacker.example.net'))->count());
        self::assertSame(1, Http::recorded()->filter(fn ($request) => str_ends_with($request[0]->url(), '/robots.txt'))->count());
    }

    public function test_identity_page_enrichment_is_bounded_to_two_even_when_more_links_exist(): void
    {
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $hostKey = 'crawl-host:'.hash('sha256', 'bounded-example.com');
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($hostKey) {
            RateLimiter::clear($hostKey);
            return match ($request->url()) {
                'https://bounded-example.com/robots.txt' => Http::response('', 404),
                'https://bounded-example.com/' => Http::response('<title>Bounded Business</title><a href="/contact">Contact</a><a href="/about">About</a><a href="/company">Company</a>', 200, ['Content-Type' => 'text/html']),
                default => Http::response('<title>Bounded Business</title>', 200, ['Content-Type' => 'text/html']),
            };
        });

        $document = app(PublicWebsiteDocumentFetcher::class)->fetch('https://bounded-example.com/');

        self::assertCount(3, $document['identity_pages']);
        self::assertSame(3, $document['_fetch_count']);
        self::assertSame(3, Http::recorded()->filter(fn ($request) => $request[0]->url() !== 'https://bounded-example.com/robots.txt')->count());
    }
}
