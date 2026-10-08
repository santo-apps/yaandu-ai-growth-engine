<?php

namespace App\WebsiteResolution;

final class CandidateDomainDiscoverySourceRegistry
{
    public function __construct(private readonly ?array $providedSources = null) {}

    /** @return list<CandidateDomainDiscoverySourceInterface> */
    public function all(): array
    {
        if ($this->providedSources !== null) return $this->providedSources;
        if (app()->environment('testing') && ! config('candidate_discovery.live_sources_in_tests', false)) {
            return [new LegacyWebsiteResolutionDiscoveryAdapter(app(DeterministicWebsiteResolutionSource::class)), app(DeterministicCandidateDomainDiscoverySource::class)];
        }
        $sources = [
            new LegacyWebsiteResolutionDiscoveryAdapter(app(LocalWebIndexResolutionSource::class)),
            new LegacyWebsiteResolutionDiscoveryAdapter(app(OSMWebsiteEvidenceSource::class)),
            new LegacyWebsiteResolutionDiscoveryAdapter(app(WikidataWebsiteResolutionSource::class)),
        ];
        $sources[] = app(BusinessEmailDomainCandidateSource::class);
        if (config('candidate_discovery.wikidata_search_enabled', false)) $sources[] = app(WikidataCandidateDomainDiscoverySource::class);
        if (app()->environment('testing')) $sources[] = new LegacyWebsiteResolutionDiscoveryAdapter(app(DeterministicWebsiteResolutionSource::class));

        return $sources;
    }
}
