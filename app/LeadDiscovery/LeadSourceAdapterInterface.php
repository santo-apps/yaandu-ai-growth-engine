<?php

namespace App\LeadDiscovery;

interface LeadSourceAdapterInterface
{
    public function key(): string;

    public function validateConfiguration(array $configuration): void;

    /** @return list<array<string, mixed>> */
    public function readCsv(string $path, int $maximumRows): array;
}
