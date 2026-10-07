<?php

namespace App\Discovery;

final class CsvDomainDiscoverySource implements DiscoverySourceInterface
{
    public function name(): string { return 'csv_import'; }
    public function capabilities(): array { return ['confirmed_csv', 'company_domain_intake']; }
    public function search(DiscoveryQuery $query): array { return array_slice($query->seeds, 0, $query->limit); }
}
