<?php

namespace App\LeadDiscovery;

final class LeadDiscoveryQueryTaxonomy
{
    /** @return list<array{key:string,query:string}> */
    public function forCampaign(object|array $campaign): array
    {
        $industry = is_array($campaign) ? ($campaign['industry'] ?? '') : ($campaign->industry ?? '');
        $industryKey = is_array($campaign)
            ? ($campaign['normalized_industry_key'] ?? null)
            : ($campaign->normalized_industry_key ?? null);
        $industryKey ??= app(LeadDiscoveryIndustryTaxonomy::class)->keyFor((string) $industry);
        $labels = config('lead_discovery.industries', []);
        $terms = config('lead_discovery.industry_query_terms', []);
        if (trim((string) $industry) !== '') {
            if ($industryKey !== null && isset($labels[$industryKey])) {
                $label = (string) $labels[$industryKey];
                $buckets = $terms[$industryKey] ?? [$industryKey => $label.' company business'];
                return array_map(static fn (string $queryKey, string $term): array => ['key' => $industryKey.'-'.$queryKey, 'query' => $term, 'industry_key' => $industryKey], array_keys($buckets), array_values($buckets));
            }

            $raw = trim(mb_substr((string) $industry, 0, 150));
            $key = 'custom-'.substr(hash('sha256', mb_strtolower($raw)), 0, 12);
            return [['key' => $key, 'query' => $raw, 'industry_key' => null, 'custom_industry' => true]];
        }

        $queries = [];
        foreach ($labels as $key => $label) {
            $buckets = $terms[$key] ?? [$key => $label.' company business'];
            foreach ($buckets as $queryKey => $term) $queries[] = ['key' => $key.'-'.$queryKey, 'query' => $term, 'industry_key' => $key];
        }

        return $queries;
    }
}
