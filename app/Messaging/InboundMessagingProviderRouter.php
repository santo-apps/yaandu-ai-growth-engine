<?php

namespace App\Messaging;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InboundMessagingProviderRouter
{
    /** @var array<string, InboundMessagingProviderInterface> */
    private array $providers = [];

    /** @param iterable<InboundMessagingProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) $this->providers[$provider->providerKey()] = $provider;
    }

    public function forTenant(string $tenantId, string $providerKey): InboundMessagingProviderInterface
    {
        $configuration = DB::table('tenant_messaging_configurations')->where('tenant_id', $tenantId)->first();
        if (! $configuration || ! $configuration->enabled || $configuration->provider !== $providerKey) {
            throw new RuntimeException('Inbound messaging is not enabled for this tenant.');
        }
        if (! in_array($providerKey, config('inbound.enabled_providers', []), true)) {
            throw new RuntimeException('Inbound provider is not allowlisted.');
        }
        $provider = $this->providers[$providerKey] ?? null;
        if (! $provider) throw new RuntimeException('Inbound provider is unavailable.');

        return $provider;
    }
}
