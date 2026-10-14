<?php

namespace App\LeadDiscovery;

use InvalidArgumentException;

final class LeadSourceAdapterRegistry
{
    public function __construct(private readonly CsvLeadSourceAdapter $csv) {}

    public function get(string $key): LeadSourceAdapterInterface
    {
        return match ($key) {
            'csv_upload' => $this->csv,
            default => throw new InvalidArgumentException('This lead discovery source adapter is not enabled.'),
        };
    }
}
