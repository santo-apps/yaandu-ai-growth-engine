<?php

namespace Tests\Unit;

use App\LeadDiscovery\LeadDiscoverySourceHealthPolicy;
use Tests\TestCase;

class LeadDiscoverySourceHealthPolicyTest extends TestCase
{
    public function test_recent_transient_osm_failure_is_held_in_cooldown(): void
    {
        config(['lead_discovery.osm_health_cooldown_seconds' => 300]);
        $source = (object) ['source_key' => 'osm_overpass', 'health_state' => 'degraded', 'last_error_code' => 'SOURCE_TEMPORARILY_UNAVAILABLE', 'updated_at' => now()->subMinutes(2)];

        self::assertSame('SOURCE_HEALTH_COOLDOWN', app(LeadDiscoverySourceHealthPolicy::class)->blockReason($source));
    }

    public function test_expired_transient_osm_failure_allows_only_the_normal_campaign_attempt(): void
    {
        config(['lead_discovery.osm_health_cooldown_seconds' => 300]);
        $source = (object) ['source_key' => 'osm_overpass', 'health_state' => 'degraded', 'last_error_code' => 'SOURCE_TIMEOUT', 'updated_at' => now()->subMinutes(6)];

        self::assertNull(app(LeadDiscoverySourceHealthPolicy::class)->blockReason($source));
    }

    public function test_nontransient_or_admin_states_never_auto_recover(): void
    {
        $policy = app(LeadDiscoverySourceHealthPolicy::class);
        foreach ([
            ['health_state' => 'degraded', 'last_error_code' => 'SOURCE_QUERY_UNSUPPORTED'],
            ['health_state' => 'auth_error', 'last_error_code' => 'SOURCE_AUTH_FAILED'],
            ['health_state' => 'not_configured', 'last_error_code' => 'SOURCE_CONFIGURATION_MISSING'],
            ['health_state' => 'disabled', 'last_error_code' => null],
        ] as $state) {
            self::assertNotNull($policy->blockReason((object) ['source_key' => 'osm_overpass', ...$state, 'updated_at' => now()->subDays(10)]));
        }
    }

    public function test_other_source_health_is_not_auto_recovered_by_osm_policy(): void
    {
        $source = (object) ['source_key' => 'wikidata', 'health_state' => 'degraded', 'last_error_code' => 'SOURCE_TIMEOUT', 'updated_at' => now()->subDays(2)];

        self::assertSame('SOURCE_TEMPORARILY_UNAVAILABLE', app(LeadDiscoverySourceHealthPolicy::class)->blockReason($source));
    }
}
