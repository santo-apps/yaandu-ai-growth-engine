<?php

namespace Tests\Unit;

use App\LeadDiscovery\BraveSearchLeadSourceAdapter;
use App\LeadDiscovery\DiscoveryQuery;
use App\LeadDiscovery\LiveLeadSourceException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BraveSearchLeadSourceAdapterTest extends TestCase
{
    private function query(?string $cursor = null): DiscoveryQuery
    {
        return new DiscoveryQuery('campaign-id', 'tenant-id', 'E-commerce & Retail', 'IN', null, null,
            'online-retailer', 'online retailer India', 10, $cursor);
    }

    private function configAdapter(): BraveSearchLeadSourceAdapter
    {
        config(['lead_discovery.live_enabled' => true, 'services.brave_search.key' => 'synthetic-secret']);
        return new BraveSearchLeadSourceAdapter;
    }

    private function approvedSource(): array
    {
        return ['source_enabled' => true, 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'storage_rights_confirmed' => true, 'storage_rights_reference' => 'Order Form 42', 'license_or_authorization' => 'Authorized plan'];
    }

    public function test_maps_documented_result_fields_and_paginates_by_page_offset(): void
    {
        Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => [[
            'title' => 'Synthetic Retailer', 'url' => 'https://synthetic-retailer.example.com/about', 'description' => 'Public business result',
        ]]]], 200, ['X-Request-Id' => 'request-123'])]);
        $page = $this->configAdapter()->search($this->query('1'), $this->approvedSource());
        self::assertSame('Synthetic Retailer', $page->candidates[0]['company_name']);
        self::assertSame('https://synthetic-retailer.example.com/about', $page->candidates[0]['website']);
        self::assertSame('Public business result', $page->candidates[0]['snippet']);
        self::assertSame(1, $page->providerMetadata['offset']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.search.brave.com/res/v1/web/search'
            && $request['q'] === 'online retailer India' && $request['count'] === 10
            && $request['offset'] === 1 && $request['country'] === 'IN'
            && $request->hasHeader('X-Subscription-Token', 'synthetic-secret'));
    }

    public function test_rejects_invalid_cursor_and_missing_storage_rights(): void
    {
        $adapter = $this->configAdapter();
        try { $adapter->search($this->query('https://unsafe'), $this->approvedSource()); self::fail('Expected invalid cursor failure'); }
        catch (LiveLeadSourceException $error) { self::assertSame('SOURCE_CURSOR_INVALID', $error->errorCode); }
        try { $adapter->validateConfiguration(['source_enabled' => true, 'authorization_status' => 'authorized', 'license_or_authorization' => 'Plan']); self::fail('Expected storage rights failure'); }
        catch (LiveLeadSourceException $error) { self::assertSame('STORAGE_RIGHTS_NOT_CONFIRMED', $error->errorCode); }
    }

    public function test_credentials_and_authorization_without_explicit_storage_rights_never_execute(): void
    {
        $adapter = $this->configAdapter();
        Http::fake();
        foreach ([
            [...$this->approvedSource(), 'storage_rights_confirmed' => false, 'storage_rights_status' => 'not_confirmed'],
            [...$this->approvedSource(), 'storage_rights_reference' => ''],
        ] as $configuration) {
            try { $adapter->search($this->query(), $configuration); self::fail('Expected storage-rights gate to reject request'); }
            catch (LiveLeadSourceException $error) { self::assertSame('STORAGE_RIGHTS_NOT_CONFIRMED', $error->errorCode); }
        }
        Http::assertNothingSent();
    }

    public function test_disabled_or_unapproved_source_never_executes_even_when_credentials_and_storage_rights_exist(): void
    {
        $adapter = $this->configAdapter();
        Http::fake();
        foreach ([
            [...$this->approvedSource(), 'source_enabled' => false],
            [...$this->approvedSource(), 'authorization_status' => 'pending'],
        ] as $configuration) {
            try { $adapter->search($this->query(), $configuration); self::fail('Expected source approval gate to reject request'); }
            catch (LiveLeadSourceException $error) { self::assertContains($error->errorCode, ['SOURCE_DISABLED', 'SOURCE_NOT_AUTHORIZED']); }
        }
        Http::assertNothingSent();
    }

    public function test_maps_auth_rate_limit_and_quota_failures_without_exposing_provider_payload(): void
    {
        $adapter = $this->configAdapter();
        $source = $this->approvedSource();
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['error' => ['detail' => 'private provider response']], 401)
            ->push(['error' => ['code' => 'RATE_LIMITED', 'detail' => 'private provider response']], 429, ['Retry-After' => '7'])
            ->push(['error' => ['detail' => 'private provider response']], 402);
        foreach ([[401, 'SOURCE_AUTH_FAILED'], [429, 'SOURCE_RATE_LIMITED'], [402, 'SOURCE_QUOTA_EXHAUSTED']] as [$status, $code]) {
            try { $adapter->search($this->query(), $source); self::fail('Expected source exception'); }
            catch (LiveLeadSourceException $error) { self::assertSame($code, $error->errorCode); self::assertStringNotContainsString('private provider response', $error->getMessage()); }
        }
    }

    public function test_provider_quota_limit_is_distinguished_from_rate_limit(): void
    {
        $adapter = $this->configAdapter();
        Http::fake(['api.search.brave.com/*' => Http::response(['error' => ['code' => 'QUOTA_LIMITED']], 429)]);
        try { $adapter->search($this->query(), $this->approvedSource()); self::fail('Expected quota failure'); }
        catch (LiveLeadSourceException $error) { self::assertSame('SOURCE_QUOTA_EXHAUSTED', $error->errorCode); }
    }
}
