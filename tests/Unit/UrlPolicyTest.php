<?php

namespace Tests\Unit;

use App\Crawling\PublicAddressResolverInterface;
use App\Crawling\UrlPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class UrlPolicyTest extends TestCase
{
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
}
