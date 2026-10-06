<?php

namespace App\Messaging;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutboundMessagingProviderRouter
{
    /** @var array<string, OutboundMessagingProviderInterface> */
    private array $providers = [];

    /** @param iterable<OutboundMessagingProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) $this->providers[$provider->providerKey()] = $provider;
    }

    public function forTenant(string $tenantId): OutboundMessagingProviderInterface
    {
        $configuration = DB::table('tenant_messaging_configurations')->where('tenant_id', $tenantId)->first();
        if (! $configuration || ! $configuration->enabled) {
            throw new RuntimeException('Outbound messaging is not enabled for this tenant.');
        }
        if (! in_array($configuration->provider, config('outbound.enabled_providers', []), true)) {
            throw new RuntimeException('The configured outbound provider is not allowlisted.');
        }

        $provider = $this->providers[$configuration->provider] ?? null;
        if (! $provider) throw new RuntimeException('The configured outbound provider is unavailable.');

        return $provider;
    }
}
