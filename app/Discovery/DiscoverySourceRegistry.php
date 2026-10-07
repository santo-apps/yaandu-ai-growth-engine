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
            default => throw new InvalidArgumentException('The selected discovery source is not enabled.'),
        };
    }
}
