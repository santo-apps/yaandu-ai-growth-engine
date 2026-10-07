<?php

namespace App\Discovery;

use Illuminate\Support\Facades\Cache;

final class ConfiguredLocationResolver implements LocationResolverInterface
{
    public function resolve(array $location): ?ResolvedLocation
    {
        $city = mb_strtolower(trim((string) ($location['city'] ?? '')));
        $country = mb_strtolower(trim((string) ($location['country'] ?? '')));
        return Cache::remember('discovery:location:'.hash('sha256', $city.'|'.$country), max(60, (int) config('discovery.location_cache_ttl_seconds', 86400)), function () use ($city, $country): ?ResolvedLocation {
            foreach ((array) config('discovery.locations', []) as $label => $entry) {
                if ($city === mb_strtolower((string) ($entry['city'] ?? $label))
                    && ($country === '' || $country === mb_strtolower((string) ($entry['country'] ?? '')))) {
                    return new ResolvedLocation((string) $label, $entry['bbox'], $entry['country'] ?? null, $entry['region'] ?? null);
                }
            }
            return null;
        });
    }
}
