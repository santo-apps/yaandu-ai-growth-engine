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
}
