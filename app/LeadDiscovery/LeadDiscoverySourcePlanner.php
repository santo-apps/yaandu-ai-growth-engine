<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Collection;

final class LeadDiscoverySourcePlanner
{
    public function __construct(private readonly LeadDiscoveryQueryTaxonomy $queries, private readonly LeadDiscoveryCountryCatalog $countries) {}

    /** @param Collection<int,object>|list<object> $sources @return list<array{source:object,query:array,partition:array}> */
    public function plan(object $campaign, iterable $sources): array
    {
        $bySource = [];
        $queries = $this->queries->forCampaign($campaign);
        $industry = trim((string) ($campaign->industry ?? ''));
        $industryKey = $campaign->normalized_industry_key ?? app(LeadDiscoveryIndustryTaxonomy::class)->keyFor($industry);
        $campaignSettings = json_decode((string) ($campaign->configuration ?? '{}'), true) ?: [];
        $maximumPartitions = max(1, min(100, (int) config('lead_discovery.live_max_partitions', 20), (int) data_get($campaignSettings, 'limits.partitions', config('lead_discovery.live_max_partitions', 20))));
        $maximumRequests = max(1, min(500, (int) config('lead_discovery.live_max_provider_requests', 50), (int) data_get($campaignSettings, 'limits.provider_requests', config('lead_discovery.live_max_provider_requests', 50))));

        foreach ($sources as $source) {
            $capability = app(LeadSourceAdapterRegistry::class)->get((string) $source->source_key)->capabilities();
            if (! $capability->supportsLiveQuery || ! $capability->persistentStorageAllowed
                || ! in_array($capability->governanceClass, ['OPEN_PERSISTABLE', 'CONDITIONAL'], true)) continue;
            $campaignConfiguration = $campaignSettings;
            $keywords = trim((string) data_get($campaignConfiguration, 'keywords', ''));
            if ($source->source_key === 'wikidata') {
                $wikidataMappings = (array) config('lead_discovery.wikidata_industries', []);
                $mappedKeys = $industryKey !== null && isset($wikidataMappings[$industryKey]['industry'])
                    ? [$industryKey]
                    : ($industry === '' ? array_keys($wikidataMappings) : []);
                $sourceQueries = array_map(static fn (string $key): array => [
                    'key' => 'industry-'.$key, 'query' => '', 'industry_key' => $key,
                ], $mappedKeys);
            } elseif ($industry === '') {
                $sourceQueries = $capability->supportsIndustryFilter
                    ? $queries
                    : [['key' => 'all-organizations', 'query' => $capability->supportsKeywordFilter ? $keywords : '', 'industry_key' => null]];
            } elseif ($capability->supportsIndustryFilter) {
                // A custom industry keeps the raw label for provenance. Sources
                // with structured support use the bounded general-business
                // mapping when no canonical mapping exists (OSM), never an
                // invented taxonomy key.
                $sourceQueries = $queries;
            } else {
                // Sources without industry filters remain usable as bounded
                // general discovery; use a keyword only when the adapter
                // explicitly supports one.
                $query = $capability->supportsKeywordFilter ? $industry : '';
                $sourceQueries = [['key' => $industryKey ? 'industry-'.$industryKey : 'custom-industry-'.substr(hash('sha256', mb_strtolower($industry)), 0, 12),
                    'query' => $query, 'industry_key' => $industryKey]];
            }
            if ($keywords !== '' && $capability->supportsKeywordFilter) {
                $sourceQueries = array_map(static fn (array $query): array => [...$query, 'query' => trim(($query['query'] ?? '').' '.$keywords)], $sourceQueries);
            }
            $campaignConfiguration = json_decode((string) $campaign->configuration, true) ?: [];
            $explicitSources = array_values(array_unique([...((array) data_get($campaignConfiguration, 'explicit_conditional_sources', [])), ...((array) data_get($campaignConfiguration, 'discovery_source_ids', []))]));
            if ($capability->governanceClass === 'CONDITIONAL' && ! in_array($source->id, $explicitSources, true)) continue;
            $partitions = $this->partitions($campaign, $source->source_key, $capability->globalCoverage);
            if (empty($campaign->country_code) && $source->source_key !== 'osm_overpass' && count($partitions) > $maximumPartitions) {
                // Rotate the bounded country window deterministically between
                // campaigns instead of permanently favoring alphabetically
                // early ISO codes. No request expands to a world-wide query.
                $rotationKey = (string) ($campaign->id ?? hash('sha256', json_encode([
                    $campaign->country_code ?? null,
                    $campaign->region ?? null,
                    $campaign->city ?? null,
                    $campaign->industry ?? null,
                ], JSON_THROW_ON_ERROR)));
                $offset = hexdec(substr(hash('sha256', $rotationKey), 0, 8)) % count($partitions);
                $partitions = array_slice([...array_slice($partitions, $offset), ...array_slice($partitions, 0, $offset)], 0, $maximumPartitions);
            }
            foreach ($sourceQueries as $query) {
                foreach (array_slice($partitions, 0, $maximumPartitions) as $partition) {
                    $bySource[$source->id][] = ['source' => $source, 'query' => $query, 'partition' => $partition];
                }
            }
        }

        // Round-robin sources so a broad all-industry plan does not let the
        // first provider consume the entire campaign request budget.
        $planned = [];
        $depth = 0;
        do {
            $added = false;
            foreach ($bySource as $tasks) {
                if (! isset($tasks[$depth])) continue;
                $planned[] = $tasks[$depth]; $added = true;
                if (count($planned) >= $maximumRequests) return $planned;
            }
            $depth++;
        } while ($added);

        return $planned;
    }

    private function partitions(object $campaign, string $sourceKey, bool $global): array
    {
        $country = strtoupper((string) ($campaign->country_code ?? ''));
        if ($sourceKey === 'india_ogd_mca') return $country === '' || $country === 'IN'
            ? [['key' => 'IN:national', 'country_code' => 'IN', 'scope' => 'national']] : [];
        if ($sourceKey === 'osm_overpass') {
            $items = config('lead_discovery.osm_partitions', []);
            return array_values(array_filter($items, static function (array $partition) use ($campaign, $country): bool {
                return ($country === '' || $partition['country_code'] === $country)
                    && (empty($campaign->region) || mb_strtolower((string) $campaign->region) === mb_strtolower((string) ($partition['region'] ?? '')))
                    && (empty($campaign->city) || mb_strtolower((string) $campaign->city) === mb_strtolower((string) ($partition['city'] ?? '')));
            }));
        }
        if ($country !== '') return $this->countries->normalize($country) !== null ? [['key' => $country, 'country_code' => $country]] : [];
        if (! $global) return [];
        return array_map(static fn (string $code): array => ['key' => $code, 'country_code' => $code], array_keys($this->countries->all()));
    }
}
