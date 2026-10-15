<?php

namespace App\LeadDiscovery;

use InvalidArgumentException;

final class LeadSourceAdapterRegistry
{
    public function __construct(
        private readonly CsvLeadSourceAdapter $csv,
        private readonly BraveSearchLeadSourceAdapter $brave,
        private readonly OpenStreetMapOverpassLeadSourceAdapter $osm,
        private readonly WikidataLeadSourceAdapter $wikidata,
        private readonly IndiaOgdMcaLeadSourceAdapter $mca,
        private readonly GleifLeadSourceAdapter $gleif,
    ) {}

    public function get(string $key): LeadSourceAdapterInterface
    {
        return match ($key) {
            'csv_upload' => $this->csv,
            'brave_search' => $this->brave,
            'osm_overpass' => $this->osm,
            'wikidata' => $this->wikidata,
            'india_ogd_mca' => $this->mca,
            'gleif' => $this->gleif,
            default => throw new InvalidArgumentException('This lead discovery source adapter is not enabled.'),
        };
    }

    /** @return list<LeadSourceCapabilities> */
    public function capabilities(): array
    {
        return array_map(fn (LeadSourceAdapterInterface $adapter) => $adapter->capabilities(), [$this->osm, $this->wikidata, $this->mca, $this->gleif, $this->csv, $this->brave]);
    }
}
