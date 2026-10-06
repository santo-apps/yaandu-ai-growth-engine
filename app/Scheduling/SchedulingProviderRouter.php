<?php

namespace App\Scheduling;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingProviderRouter
{
    /** @var array<string, SchedulingProviderInterface> */
    private array $providers = [];

    /** @param iterable<SchedulingProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) $this->providers[$provider->providerKey()] = $provider;
    }

    public function forTenant(string $tenantId): SchedulingProviderInterface
    {
        $configuration = DB::table('tenant_scheduling_configurations')->where('tenant_id', $tenantId)->first();
        if (! $configuration || ! $configuration->enabled) throw new RuntimeException('Scheduling is not enabled for this tenant.');
        if (! in_array($configuration->provider, config('scheduling.enabled_providers', []), true)) throw new RuntimeException('Scheduling provider is not allowlisted.');
        $provider = $this->providers[$configuration->provider] ?? null;
        if (! $provider) throw new RuntimeException('Scheduling provider is unavailable.');

        return $provider;
    }

    public function forExistingMeeting(string $providerKey): SchedulingProviderInterface
    {
        if (! in_array($providerKey, config('scheduling.enabled_providers', []), true)) throw new RuntimeException('The meeting provider is no longer allowlisted.');
        $provider = $this->providers[$providerKey] ?? null;
        if (! $provider) throw new RuntimeException('The meeting provider is unavailable.');
        return $provider;
    }
}
