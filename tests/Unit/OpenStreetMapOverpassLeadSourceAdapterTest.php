<?php

namespace Tests\Unit;

use App\LeadDiscovery\DiscoveryQuery;
use App\LeadDiscovery\LiveLeadSourceException;
use App\LeadDiscovery\OpenStreetMapOverpassLeadSourceAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenStreetMapOverpassLeadSourceAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lead_discovery.live_enabled' => true,
            'lead_discovery.user_agent' => 'PulamTest/1.0 (ops@example.test)',
            'lead_discovery.overpass_approved_hosts' => ['overpass-api.de', 'overpass.private.coffee'],
            'lead_discovery.overpass_endpoints' => [
                ['url' => 'https://overpass-api.de/api/interpreter', 'name' => 'FOSSGIS main'],
                ['url' => 'https://overpass.private.coffee/api/interpreter', 'name' => 'Private.coffee'],
            ],
            'lead_discovery.overpass_query_timeout_seconds' => 25,
            'lead_discovery.overpass_connect_timeout_seconds' => 5,
            'lead_discovery.overpass_http_timeout_seconds' => 35,
            'lead_discovery.overpass_logical_request_timeout_seconds' => 40,
        ]);
    }

    public function test_http_statuses_have_distinct_safe_classifications(): void
    {
        $this->assertStatusFailure(429, 'SOURCE_RATE_LIMITED', 23);
    }

    public function test_502_is_temporary_availability_failure(): void
    {
        $this->assertStatusFailure(502, 'SOURCE_TEMPORARILY_UNAVAILABLE');
    }

    public function test_503_is_temporary_availability_failure(): void
    {
        $this->assertStatusFailure(503, 'SOURCE_TEMPORARILY_UNAVAILABLE');
    }

    public function test_other_server_errors_are_temporary_availability_failures(): void
    {
        $this->assertStatusFailure(500, 'SOURCE_TEMPORARILY_UNAVAILABLE');
    }

    public function test_504_is_a_timeout(): void
    {
        $this->assertStatusFailure(504, 'SOURCE_TIMEOUT');
    }

    public function test_primary_502_then_approved_fallback_succeeds_and_records_endpoint(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(['temporary' => true], 502),
            'overpass.private.coffee/*' => Http::response(['elements' => []], 200),
        ]);

        $page = app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());

        self::assertSame('Private.coffee', $page->providerMetadata['endpoint_name']);
        self::assertSame('https://overpass.private.coffee/api/interpreter', $page->providerMetadata['endpoint_url']);
        self::assertCount(2, Http::recorded());
    }

    public function test_primary_connection_failure_falls_back_once_to_approved_endpoint(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::failedConnection('synthetic connection timeout'),
            'overpass.private.coffee/*' => Http::response(['elements' => []], 200),
        ]);

        $page = app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());

        self::assertSame('Private.coffee', $page->providerMetadata['endpoint_name']);
        self::assertCount(2, Http::recorded());
    }

    public function test_primary_timeout_then_approved_fallback_succeeds(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::failedConnection('synthetic timeout'),
            'overpass.private.coffee/*' => Http::response(['elements' => []], 200),
        ]);

        $page = app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());

        self::assertTrue($page->exhausted);
        self::assertSame('Private.coffee', $page->providerMetadata['endpoint_name']);
        self::assertCount(2, Http::recorded());
    }

    public function test_client_error_does_not_fallback(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(['error' => 'private provider response'], 403),
            'overpass.private.coffee/*' => Http::response(['elements' => []], 200),
        ]);

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());
            self::fail('A client error must fail without fallback.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_RESPONSE_INVALID', $exception->errorCode);
            self::assertStringNotContainsString('private provider response', $exception->getMessage());
            self::assertCount(1, Http::recorded());
        }
    }

    public function test_malformed_success_response_does_not_fallback(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(['unexpected' => []], 200),
            'overpass.private.coffee/*' => Http::response(['elements' => []], 200),
        ]);

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());
            self::fail('Malformed success semantics must fail without fallback.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_RESPONSE_INVALID', $exception->errorCode);
            self::assertCount(1, Http::recorded());
        }
    }

    public function test_arbitrary_endpoint_host_is_rejected_before_request(): void
    {
        config(['lead_discovery.overpass_endpoints' => [
            ['url' => 'https://attacker.example/api/interpreter', 'name' => 'Unapproved'],
        ]]);
        Http::fake();

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());
            self::fail('An arbitrary endpoint host must be rejected.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_CONFIGURATION_MISSING', $exception->errorCode);
            Http::assertNothingSent();
        }
    }

    public function test_tenant_configuration_cannot_override_the_approved_endpoint_pool(): void
    {
        Http::fake();

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), [...$this->approvedSource(), 'endpoint_url' => 'https://attacker.example/api/interpreter']);
            self::fail('Tenant endpoint overrides must be rejected.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_CONFIGURATION_MISSING', $exception->errorCode);
            Http::assertNothingSent();
        }
    }

    public function test_connection_failure_without_fallback_is_classified_as_timeout(): void
    {
        config(['lead_discovery.overpass_endpoints' => [
            ['url' => 'https://overpass-api.de/api/interpreter', 'name' => 'FOSSGIS main'],
        ]]);
        Http::fake(['overpass-api.de/*' => Http::failedConnection()]);

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());
            self::fail('A connection failure must be classified.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_TIMEOUT', $exception->errorCode);
        }
    }

    public function test_bounded_queries_use_twenty_five_second_overpass_timeout_and_precise_ecommerce_selectors(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []], 200)]);
        app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query('ecommerce_retail'), $this->approvedSource());

        Http::assertSent(function ($request): bool {
            $query = urldecode($request->body());
            return str_contains($query, '[out:json][timeout:25]')
                && str_contains($query, '["shop"="furniture"]')
                && ! str_contains($query, '["shop"]')
                && ! str_contains($query, '[out:json][timeout:20]');
        });
        self::assertSame(5, config('lead_discovery.overpass_connect_timeout_seconds'));
        self::assertSame(35, config('lead_discovery.overpass_http_timeout_seconds'));
        self::assertSame(40, config('lead_discovery.overpass_logical_request_timeout_seconds'));
    }

    public function test_two_degree_bbox_limit_is_preserved(): void
    {
        $query = new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'retail', '', 10, null,
            ['bbox' => [24.0, 55.0, 26.01, 55.5], 'country_code' => 'AE'], 'ecommerce_retail');
        Http::fake();

        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($query, $this->approvedSource());
            self::fail('An oversized bbox must be rejected.');
        } catch (LiveLeadSourceException $exception) {
            self::assertSame('SOURCE_PARTITION_INVALID', $exception->errorCode);
            Http::assertNothingSent();
        }
    }

    private function query(string $industry = 'healthcare'): DiscoveryQuery
    {
        return new DiscoveryQuery('campaign', 'tenant', '', 'AE', null, null, 'clinic', 'clinic hospital', 10, null,
            ['key' => 'AE:DU:Dubai', 'country_code' => 'AE', 'city' => 'Dubai', 'region' => 'Dubai', 'bbox' => [25.0, 55.0, 25.2, 55.4]], $industry);
    }

    private function approvedSource(): array
    {
        return ['source_enabled' => true, 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'storage_rights_confirmed' => true, 'storage_rights_reference' => 'synthetic-license-reference', 'license_or_authorization' => 'Synthetic source terms'];
    }

    private function assertStatusFailure(int $status, string $expected, ?int $retryAfter = null): void
    {
        config(['lead_discovery.overpass_endpoints' => [['url' => 'https://overpass-api.de/api/interpreter', 'name' => 'FOSSGIS main']]]);
        Http::fake(['overpass-api.de/*' => Http::response(['error' => 'provider detail must not leak'], $status, $status === 429 ? ['Retry-After' => (string) $retryAfter] : [])]);
        try {
            app(OpenStreetMapOverpassLeadSourceAdapter::class)->search($this->query(), $this->approvedSource());
            self::fail("HTTP {$status} should fail safely.");
        } catch (LiveLeadSourceException $exception) {
            self::assertSame($expected, $exception->errorCode);
            self::assertStringNotContainsString('provider detail', $exception->getMessage());
            if ($retryAfter !== null) self::assertSame($retryAfter, $exception->retryAfter);
        }
        if ($status === 429) self::assertCount(1, Http::recorded(), '429 must not bypass rate limiting through another endpoint.');
    }
}
