<?php

namespace App\LeadDiscovery;

final class LeadDiscoveryQueryTaxonomy
{
    /** @return list<array{key:string,query:string}> */
    public function forCampaign(object|array $campaign): array
    {
        $industry = is_array($campaign) ? ($campaign['industry'] ?? '') : ($campaign->industry ?? '');
        $country = is_array($campaign) ? ($campaign['country_code'] ?? '') : ($campaign->country_code ?? '');
        $city = is_array($campaign) ? ($campaign['city'] ?? '') : ($campaign->city ?? '');
        $region = is_array($campaign) ? ($campaign['region'] ?? '') : ($campaign->region ?? '');
        $buckets = config('lead_discovery.taxonomy.ecommerce_retail.queries', []);
        if (mb_strtolower(trim((string) $industry)) !== mb_strtolower(config('lead_discovery.taxonomy.ecommerce_retail.industry'))) return [];
        $location = trim(implode(' ', array_filter([(string) $city, (string) $region, (string) $country])));
        return array_map(static fn (string $key, string $term): array => [
            'key' => $key,
            'query' => trim($term.' '.$location),
        ], array_keys($buckets), array_values($buckets));
    }
}
