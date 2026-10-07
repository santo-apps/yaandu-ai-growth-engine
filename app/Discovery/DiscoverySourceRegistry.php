<?php

namespace App\Discovery;

use InvalidArgumentException;

final class DiscoverySourceRegistry
{
    public function get(string $name): DiscoverySourceInterface
    {
        return match ($name) {
            'supplied_seed' => app(SuppliedSeedDiscoverySource::class),
            'csv_import' => app(CsvDomainDiscoverySource::class),
            'deterministic_local' => app(DeterministicDiscoverySource::class),
            'openstreetmap' => app(OpenStreetMapDiscoverySource::class),
            'location_open_web' => app(OpenStreetMapDiscoverySource::class),
            'web_search' => $this->webSearch(),
            default => throw new InvalidArgumentException('The selected discovery source is not enabled.'),
        };
    }

    private function webSearch(): DiscoverySourceInterface
    {
        if (! app()->environment(['local', 'testing']) || ! config('discovery.allow_deterministic', false)) {
            throw new InvalidArgumentException('No production web search adapter is enabled pending source policy review.');
        }
        return app(WebSearchDiscoverySource::class);
    }
}
