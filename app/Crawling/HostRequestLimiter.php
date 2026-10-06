<?php

namespace App\Crawling;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class HostRequestLimiter
{
    public function run(string $host, int $timeoutSeconds, Closure $request): mixed
    {
        $key = 'crawl-host:'.hash('sha256', strtolower($host));
        $timeoutSeconds = max(1, $timeoutSeconds);
        $lock = Cache::lock($key.':lock', $timeoutSeconds + 15);

        return $lock->block($timeoutSeconds, function () use ($key, $timeoutSeconds, $request) {
            $deadline = microtime(true) + $timeoutSeconds;
            while (RateLimiter::tooManyAttempts($key, 1)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) throw new RuntimeException('Crawl rate-limit wait exceeded the request timeout.');
                $sleep = min(250_000, max(10_000, RateLimiter::availableIn($key) * 100_000), (int) ($remaining * 1_000_000));
                usleep($sleep);
            }
            RateLimiter::hit($key, 1);
            return $request();
        });
    }
}
