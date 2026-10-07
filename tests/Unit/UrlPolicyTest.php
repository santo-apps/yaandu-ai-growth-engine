<?php

namespace Tests\Unit;

use App\Crawling\PublicAddressResolverInterface;
use App\Crawling\PublicSuffixDomainMatcher;
use App\Crawling\UrlPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class UrlPolicyTest extends TestCase
{
    public function test_public_suffix_list_respects_multilevel_and_private_suffixes(): void
    {
        $domains = new PublicSuffixDomainMatcher;

        self::assertTrue($domains->sameRegistrableDomain('www.example.co.uk', 'example.co.uk'));
        self::assertFalse($domains->sameRegistrableDomain('alice.github.io', 'bob.github.io'));
        self::assertFalse($domains->sameRegistrableDomain('example.com', 'example.net'));
    }

    public function test_fetch_resolves_and_validates_each_redirect_hop(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public array $hosts = [];

            public function resolve(string $host): array
            {
                $this->hosts[] = $host;

                return ['8.8.8.8'];
            }
        };
        Http::fake(fn (Request $request) => $request->url() === 'https://example.test/'
            ? Http::response('', 302, ['Location' => '/about'])
            : Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']));

        $response = (new UrlPolicy($resolver))->fetch('https://example.test/');

        self::assertSame(200, $response->status());
        self::assertSame(['example.test', 'example.test'], $resolver->hosts);
        self::assertCount(2, Http::recorded());
    }

    public function test_fetch_rejects_private_dns_result_before_http_request(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        };
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        try {
            (new UrlPolicy($resolver))->fetch('https://example.test/');
        } finally {
            self::assertCount(0, Http::recorded());
        }
    }

    public function test_cross_domain_and_https_downgrade_redirects_are_rejected(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        Http::fake(['https://example.test/*' => Http::response('', 302, ['Location' => 'http://example.test/insecure'])]);

        $this->expectException(InvalidArgumentException::class);
        (new UrlPolicy($resolver))->fetch('https://example.test/');
    }

    public function test_loopback_private_link_local_metadata_and_ipv6_targets_are_rejected(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array
            {
                return match (strtolower(trim($host, '[]'))) {
                    'metadata.google.internal' => ['169.254.169.254'],
                    'private.example' => ['10.0.0.8'],
                    default => [trim($host, '[]')],
                };
            }
        };
        $policy = new UrlPolicy($resolver);
        foreach (['http://localhost/', 'http://127.0.0.1/', 'http://10.0.0.8/', 'http://169.254.169.254/', 'http://[::1]/', 'http://[fc00::1]/', 'http://[fe80::1]/', 'http://metadata.google.internal/', 'https://private.example/'] as $url) {
            try { $policy->validatePublicHttpUrl($url); self::fail('Expected unsafe target to be rejected: '.$url); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function test_redirect_to_a_private_dns_answer_is_rejected_before_second_request(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            private int $calls = 0;
            public function resolve(string $host): array { return ++$this->calls === 1 ? ['8.8.8.8'] : ['192.168.0.4']; }
        };
        Http::fake(['https://example.test/*' => Http::response('', 302, ['Location' => '/private'])]);
        try { (new UrlPolicy($resolver))->fetch('https://example.test/'); self::fail('Expected private redirect answer to be rejected.'); }
        catch (InvalidArgumentException) { self::assertCount(1, Http::recorded()); }
    }

    public function test_apex_to_www_and_www_to_apex_canonical_redirects_are_allowed(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public array $hosts = [];
            public function resolve(string $host): array { $this->hosts[] = strtolower($host); return ['8.8.8.8']; }
        };
        Http::fake(fn (Request $request) => match ($request->url()) {
            'https://example.com/' => Http::response('', 301, ['Location' => 'https://www.example.com/']),
            'https://www.example.com/' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
            'https://www.example.com/about' => Http::response('', 301, ['Location' => 'https://example.com/about']),
            default => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
        });

        self::assertSame(200, (new UrlPolicy($resolver))->fetch('https://example.com/')->status());
        self::assertSame(200, (new UrlPolicy($resolver))->fetch('https://www.example.com/about')->status());
        self::assertContains('www.example.com', $resolver->hosts);
        self::assertContains('example.com', $resolver->hosts);
    }

    public function test_http_to_https_and_same_site_subdomain_redirects_are_allowed(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array { return ['8.8.8.8']; }
        };
        Http::fake(fn (Request $request) => match ($request->url()) {
            'http://example.com/' => Http::response('', 301, ['Location' => 'https://example.com/']),
            'https://example.com/' => Http::response('', 302, ['Location' => 'https://shop.example.com/']),
            default => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
        });

        self::assertSame(200, (new UrlPolicy($resolver))->fetch('http://example.com/')->status());
        self::assertSame(200, (new UrlPolicy($resolver))->fetch('https://example.com/')->status());
    }

    public function test_unrelated_redirect_is_rejected_after_public_destination_revalidation(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public array $hosts = [];
            public function resolve(string $host): array { $this->hosts[] = strtolower($host); return ['8.8.8.8']; }
        };
        Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => 'https://unrelated.net/'])]);

        try { (new UrlPolicy($resolver))->fetch('https://example.com/'); self::fail('Expected unrelated registrable domain to be rejected.'); }
        catch (InvalidArgumentException $error) {
            self::assertSame('Cross-domain redirects are not allowed.', $error->getMessage());
            self::assertSame(['example.com', 'unrelated.net'], $resolver->hosts);
            self::assertCount(1, Http::recorded());
        }
    }

    public function test_redirects_to_local_private_ipv6_and_metadata_targets_remain_blocked(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array
            {
                return match (strtolower(trim($host, '[]'))) {
                    'private.example' => ['10.0.0.8'], 'metadata.google.internal' => ['169.254.169.254'],
                    default => ['8.8.8.8'],
                };
            }
        };
        foreach ([
            'http://localhost/', 'http://127.0.0.1/', 'http://[::1]/', 'http://[fc00::1]/',
            'http://[fe80::1]/', 'http://169.254.169.254/', 'http://metadata.google.internal/', 'https://private.example/',
        ] as $destination) {
            Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => $destination])]);
            try { (new UrlPolicy($resolver))->fetch('https://example.com/'); self::fail('Expected unsafe redirect target to be rejected: '.$destination); }
            catch (InvalidArgumentException) { self::assertCount(1, Http::recorded()); }
            Http::preventStrayRequests(false);
            Http::fake([]);
        }
    }

    public function test_redirect_loop_and_depth_overflow_remain_bounded(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array { return ['8.8.8.8']; }
        };
        Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => $request->url() === 'https://example.com/a' ? '/b' : '/a']));

        try { (new UrlPolicy($resolver))->fetch('https://example.com/a'); self::fail('Expected redirect loop to stop at the redirect limit.'); }
        catch (InvalidArgumentException $error) {
            self::assertSame('The URL exceeded the redirect limit.', $error->getMessage());
            self::assertCount(4, Http::recorded());
        }
    }

    public function test_redirect_depth_overflow_remains_bounded_for_a_non_looping_chain(): void
    {
        $resolver = new class implements PublicAddressResolverInterface
        {
            public function resolve(string $host): array { return ['8.8.8.8']; }
        };
        Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => '/hop-'.((int) substr((string) parse_url($request->url(), PHP_URL_PATH), 5) + 1)]));

        try { (new UrlPolicy($resolver))->fetch('https://example.com/hop-0'); self::fail('Expected a long redirect chain to stop at the redirect limit.'); }
        catch (InvalidArgumentException $error) {
            self::assertSame('The URL exceeded the redirect limit.', $error->getMessage());
            self::assertCount(4, Http::recorded());
        }
    }
}
