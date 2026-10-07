<?php

namespace App\Discovery;

final class SuppliedSeedDiscoverySource implements DiscoverySourceInterface
{
    public function name(): string { return 'supplied_seed'; }
    public function capabilities(): array { return ['user_supplied_domains', 'company_metadata']; }
    public function search(DiscoveryQuery $query): array { return array_slice($query->seeds, 0, $query->limit); }
}
