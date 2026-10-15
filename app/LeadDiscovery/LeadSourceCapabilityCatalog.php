<?php

namespace App\LeadDiscovery;

final class LeadSourceCapabilityCatalog
{
    public function __construct(private readonly LeadSourceAdapterRegistry $registry) {}

    /** @return list<LeadSourceCapabilities> */
    public function selectable(): array
    {
        return $this->registry->capabilities();
    }

    public function get(string $key): ?LeadSourceCapabilities
    {
        foreach ($this->selectable() as $capabilities) if ($capabilities->sourceKey === $key) return $capabilities;

        return $key === 'google_maps' ? new LeadSourceCapabilities(
            'google_maps', 'prohibited_bulk_source', false, false, false, false, true, true, true, true, true,
            false, [], [], false, false, false, false, 'Google Maps Platform terms', null, null, 0, 'unsupported',
            'PROHIBITED_FOR_BULK_STORAGE', 'Provider terms prohibit bulk extraction and persistent storage for this use case.',
        ) : null;
    }
}
