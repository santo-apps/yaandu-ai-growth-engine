<?php

namespace App\LeadDiscovery;

use InvalidArgumentException;

final class LeadSourceAdapterRegistry
{
    public function __construct(private readonly CsvLeadSourceAdapter $csv, private readonly BraveSearchLeadSourceAdapter $brave) {}

    public function get(string $key): LeadSourceAdapterInterface
    {
        return match ($key) {
            'csv_upload' => $this->csv,
            'brave_search' => $this->brave,
            default => throw new InvalidArgumentException('This lead discovery source adapter is not enabled.'),
        };
    }
}
