<?php

namespace App\Discovery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OverpassClient
{
    public function query(string $ql, string $cacheKey): array
    {
        $ttl = max(1, (int) config('discovery.osm_cache_ttl_seconds', 21600));
        try {
        $result = Cache::remember('overpass:'.hash('sha256', $cacheKey), $ttl, function () use ($ql): array {
            $endpoint = (string) config('discovery.overpass_endpoint');
            $host = parse_url($endpoint, PHP_URL_HOST);
            $allowed = (array) config('discovery.overpass_allowed_hosts', []);
            $port = parse_url($endpoint, PHP_URL_PORT);
            if (! $host || parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ($port !== null && $port !== 443)
                || parse_url($endpoint, PHP_URL_USER) !== null || parse_url($endpoint, PHP_URL_PASS) !== null
                || ! in_array(strtolower($host), $allowed, true)) {
                throw new RuntimeException('Overpass endpoint configuration is not permitted.');
            }

            $lock = Cache::lock('overpass:request-lock', max(5, (int) config('discovery.osm_timeout_seconds', 20) + 5));
            if (! $lock->get()) throw new RuntimeException('Overpass source is rate limited; retry later.');
            try {
                $delay = max(0, (int) config('discovery.osm_min_delay_ms', 1000));
                $last = (int) Cache::get('overpass:last-request-ms', 0);
                $wait = $delay - ((int) floor(microtime(true) * 1000) - $last);
                if ($wait > 0) usleep(min($wait, 5000) * 1000);
                Cache::put('overpass:last-request-ms', (int) floor(microtime(true) * 1000), 120);

                $tries = min(2, max(1, (int) config('discovery.osm_retries', 2)));
                for ($attempt = 1; $attempt <= $tries; $attempt++) {
                    try {
                        $response = Http::withHeaders(['User-Agent' => (string) config('discovery.osm_user_agent')])
                            ->acceptJson()->connectTimeout(5)->timeout(min(30, max(1, (int) config('discovery.osm_timeout_seconds', 20))))
                            ->withBody(http_build_query(['data' => $ql]), 'application/x-www-form-urlencoded')->post($endpoint);
                    } catch (\Illuminate\Http\Client\ConnectionException $error) {
                        if ($attempt < $tries) { usleep(min(500 * $attempt, 1500) * 1000); continue; }
                        throw $error;
                    }
                    $maxBytes = (int) config('discovery.osm_max_response_bytes', 2_000_000);
                    $body = $response->body();
                    if (strlen($body) > $maxBytes) throw new RuntimeException('Overpass response exceeded the configured size limit.');
                    if (in_array($response->status(), [429, 502, 503, 504], true) && $attempt < $tries) {
                        usleep(min(500 * $attempt, 1500) * 1000);
                        continue;
                    }
                    if (! $response->successful()) throw new RuntimeException('Overpass source returned HTTP '.$response->status().'.');
                    $decoded = json_decode($body, true);
                    if (! is_array($decoded) || ! is_array($decoded['elements'] ?? null)) throw new RuntimeException('Overpass returned malformed data.');
                    return array_slice($decoded['elements'], 0, (int) config('discovery.osm_max_elements', 100));
                }
                throw new RuntimeException('Overpass source is unavailable.');
            } finally {
                $lock->release();
            }
        });
        app(DiscoverySourceHealth::class)->markSuccess();
        return $result;
        } catch (\Throwable $error) {
            app(DiscoverySourceHealth::class)->markFailure($error->getMessage(), str_contains(mb_strtolower($error->getMessage()), 'rate limited') || str_contains(mb_strtolower($error->getMessage()), '429'));
            throw $error;
        }
    }
}
