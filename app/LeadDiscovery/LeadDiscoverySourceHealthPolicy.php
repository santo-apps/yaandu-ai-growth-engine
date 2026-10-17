<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Carbon;

/** Conservative recovery policy for transient source health state. */
final class LeadDiscoverySourceHealthPolicy
{
    private const TRANSIENT_ERRORS = ['SOURCE_TIMEOUT', 'SOURCE_TEMPORARILY_UNAVAILABLE', 'SOURCE_RATE_LIMITED'];

    public function blockReason(object $source): ?string
    {
        $state = (string) ($source->health_state ?? 'not_configured');
        if (! in_array($state, ['degraded', 'auth_error', 'not_configured', 'disabled'], true)) return null;

        // Only a previously transient OSM failure may recover by age. Disabled,
        // authorization, rights and configuration failures require operator action.
        if ((string) ($source->source_key ?? '') === 'osm_overpass'
            && $state === 'degraded'
            && in_array((string) ($source->last_error_code ?? ''), self::TRANSIENT_ERRORS, true)
            && ! empty($source->updated_at)) {
            $failedAt = Carbon::parse($source->updated_at);
            $cooldown = max(60, min(3600, (int) config('lead_discovery.osm_health_cooldown_seconds', 300)));
            if ($failedAt->addSeconds($cooldown)->isPast()) return null;
            return 'SOURCE_HEALTH_COOLDOWN';
        }

        return $state === 'degraded' ? 'SOURCE_TEMPORARILY_UNAVAILABLE' : match ($state) {
            'auth_error' => 'SOURCE_NOT_AUTHORIZED',
            'disabled' => 'SOURCE_DISABLED',
            default => 'SOURCE_CONFIGURATION_MISSING',
        };
    }

    public function cooldownSeconds(): int
    {
        return max(60, min(3600, (int) config('lead_discovery.osm_health_cooldown_seconds', 300)));
    }
}
