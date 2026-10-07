<?php

namespace App\Discovery;

use LogicException;

final class DeterministicDiscoverySource implements DiscoverySourceInterface, DeterministicCandidateFixtureSourceInterface
{
    public function __construct()
    {
        if (! app()->environment(['local', 'testing']) || ! config('discovery.allow_deterministic', false)) {
            throw new LogicException('Deterministic discovery is available only in explicitly enabled local/test environments.');
        }
    }

    public function name(): string { return 'deterministic_local'; }
    public function capabilities(): array { return ['fictional_acceptance_data']; }

    public function fixtureForCandidate(string $sourceReference): ?array
    {
        return match ($sourceReference) {
            'fixture:new-high' => ['status' => 200, 'response_time_ms' => 120, 'html' => '<html><head><title>Noura Market</title><meta name="description" content="Fictional online retailer"><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://noura-market.example/"></head><body><h1>Noura Market</h1><p>Fictional retail ecommerce business with an older storefront layout. Mobile navigation is difficult and the checkout is slow. Contact sales at <a href="mailto:sales@noura-market.example">sales@noura-market.example</a>.</p></body></html>'],
            'fixture:new-low' => ['status' => 200, 'response_time_ms' => 140, 'html' => '<html><head><title>Cedar &amp; Loom</title><meta name="viewport" content="width=device-width"></head><body><h1>Cedar &amp; Loom</h1><p>Fictional retail catalog. No public business contact details or additional website findings are included in this acceptance fixture.</p></body></html>'],
            'fixture:existing' => ['status' => 200, 'response_time_ms' => 90, 'html' => '<html><head><title>Northstar Retail</title><meta name="viewport" content="width=device-width"></head><body><h1>Northstar Retail</h1><p>Fictional existing retail company website.</p></body></html>'],
            'fixture:unreachable' => ['status' => 503, 'html' => ''],
            default => null,
        };
    }
    public function search(DiscoveryQuery $query): array
    {
        return array_slice([
            ['name' => 'Noura Market (Fictional)', 'website' => 'https://noura-market.example', 'country' => 'UAE', 'city' => 'Dubai', 'industry' => 'E-commerce / Retail', 'source_reference' => 'fixture:new-high'],
            ['name' => 'Cedar & Loom (Fictional)', 'website' => 'https://www.cedar-loom.example/about', 'country' => 'UAE', 'city' => 'Abu Dhabi', 'industry' => 'Retail', 'source_reference' => 'fixture:new-low'],
            ['name' => 'Unsafe Example (Fictional)', 'website' => 'http://127.0.0.1/admin', 'country' => 'UAE', 'industry' => 'Retail', 'source_reference' => 'fixture:invalid'],
            ['name' => 'Offline Atelier (Fictional)', 'website' => 'https://offline-atelier.invalid', 'country' => 'UAE', 'city' => 'Sharjah', 'industry' => 'Retail', 'source_reference' => 'fixture:unreachable'],
            ['name' => 'Noura Market Duplicate (Fictional)', 'website' => 'https://noura-market.example/contact', 'country' => 'UAE', 'industry' => 'E-commerce / Retail', 'source_reference' => 'fixture:duplicate'],
            ['name' => 'Northstar Existing (Fictional)', 'website' => 'https://northstar-retail.fixture.test', 'country' => 'UAE', 'industry' => 'Retail', 'source_reference' => 'fixture:existing'],
        ], 0, max(0, $query->limit));
    }
}
