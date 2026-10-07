<?php

namespace App\Discovery;

use Illuminate\Support\Facades\Cache;

final class DiscoverySourceHealth
{
    public function status(): array
    {
        $record = Cache::get('discovery-source-health:openstreetmap', []);
        return [
            'openstreetmap' => [
                'configured' => (bool) config('discovery.overpass_endpoint') && (bool) config('discovery.osm_user_agent'),
                'enabled' => (bool) config('discovery.osm_enabled', false),
                'available' => $record['available'] ?? null,
                'last_success_at' => $record['last_success_at'] ?? null,
                'last_failure_at' => $record['last_failure_at'] ?? null,
                'last_failure' => $record['last_failure'] ?? null,
                'rate_limited' => (bool) ($record['rate_limited'] ?? false),
            ],
            'web_search' => ['configured' => false, 'enabled' => false, 'available' => null, 'state' => 'Production search adapter pending policy review.'],
        ];
    }

    public function markSuccess(): void
    {
        $data = Cache::get('discovery-source-health:openstreetmap', []);
        Cache::put('discovery-source-health:openstreetmap', [...$data, 'available' => true, 'last_success_at' => now()->toIso8601String(), 'last_failure' => null, 'rate_limited' => false], 2592000);
    }

    public function markFailure(string $message, bool $rateLimited = false): void
    {
        $data = Cache::get('discovery-source-health:openstreetmap', []);
        Cache::put('discovery-source-health:openstreetmap', [...$data, 'available' => false, 'last_failure_at' => now()->toIso8601String(),
            'last_failure' => mb_substr($message, 0, 240), 'rate_limited' => $rateLimited], 2592000);
    }
}
