<?php

namespace App\LeadDiscovery;

interface SearchLeadSourceAdapterInterface extends LeadSourceAdapterInterface
{
    public function search(DiscoveryQuery $query, array $sourceConfiguration): DiscoveryPage;
}
