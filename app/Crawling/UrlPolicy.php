<?php

namespace App\Crawling;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;

final class UrlPolicy
{
    private readonly HostRequestLimiter $hostLimiter;

    public function __construct(private readonly PublicAddressResolverInterface $addresses, ?HostRequestLimiter $hostLimiter = null)
    {
        $this->hostLimiter = $hostLimiter ?? new HostRequestLimiter();
    }

    public function validatePublicHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only public HTTP and HTTPS URLs are allowed.');
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            throw new InvalidArgumentException('The URL port is not allowed.');
        }
        $rawHost = trim($parts['host'], '[]');
        if (str_ends_with($rawHost, '.')) {
            throw new InvalidArgumentException('Trailing-dot hostnames are not allowed.');
        }
        $host = strtolower($rawHost);
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Local network hosts are not allowed.');
        }
        if (! filter_var($host, FILTER_VALIDATE_IP) && (! preg_match('/^[a-z0-9.-]+$/', $host) || ctype_digit(str_replace('.', '', $host)))) {
            throw new InvalidArgumentException('The URL hostname is invalid.');
        }
        $addresses = $this->addresses->resolve($host);
        if ($addresses === []) {
            throw new InvalidArgumentException('The URL host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('The URL resolves to a non-public address.');
            }
        }

        return [$host, $addresses];
    }

    public function fetch(string $url, ?CrawlBudget $budget = null, ?int $timeoutSeconds = null): Response
    {
        $timeoutSeconds = min(30, max(1, min($timeoutSeconds ?? 15, (int) config('crawling.request_timeout_seconds', 15))));
        $initial = $this->normalizeHost($url);
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $budget?->consumeFetch();
            [$host, $ips] = $this->validatePublicHttpUrl($currentUrl);
            if ($host !== $initial['host']) {
                throw new InvalidArgumentException('Cross-domain redirects are not allowed.');
            }
            if ($initial['scheme'] === 'https' && strtolower((string) parse_url($currentUrl, PHP_URL_SCHEME)) !== 'https') {
                throw new InvalidArgumentException('HTTPS redirects cannot downgrade to HTTP.');
            }

            $port = (int) (parse_url($currentUrl, PHP_URL_PORT) ?? (strtolower((string) parse_url($currentUrl, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
            $resolveHost = str_contains($host, ':') ? '['.$host.']' : $host;
            $resolveIp = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $response = $this->hostLimiter->run($host, $timeoutSeconds, fn () => Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [CURLOPT_RESOLVE => ["{$resolveHost}:{$port}:{$resolveIp}"]],
                ])->connectTimeout(min(5, $timeoutSeconds))->timeout($timeoutSeconds)
                    ->withHeaders(['User-Agent' => 'YaanduGrowthBot/1.0 (+contact: crawler@yaandu.com)'])->get($currentUrl));

            if (! $response->redirect()) {
                $stream = $response->toPsrResponse()->getBody();
                $body = '';
                $maxBytes = min(10_000_000, max(1024, (int) config('crawling.max_response_bytes', 5_000_000)));
                while (! $stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') break;
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $stream->close();
                        throw new InvalidArgumentException('Response exceeds the configured crawl limit.');
                    }
                    $body .= $chunk;
                }
                return new Response($response->toPsrResponse()->withBody(Utils::streamFor($body)));
            }
            $response->toPsrResponse()->getBody()->close();

            if ($redirects === 3) {
                throw new InvalidArgumentException('The URL exceeded the redirect limit.');
            }
            $target = $response->header('Location');
            if (! $target) {
                throw new InvalidArgumentException('Redirect had no destination.');
            }
            $currentUrl = (new UrlResolver)->resolve($currentUrl, $target);
        }

        throw new InvalidArgumentException('The URL exceeded the redirect limit.');
    }

    private function normalizeHost(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new InvalidArgumentException('Only public HTTP and HTTPS URLs are allowed.');
        }

        return ['host' => strtolower(trim($parts['host'], '[]')), 'scheme' => strtolower($parts['scheme'] ?? '')];
    }
}
