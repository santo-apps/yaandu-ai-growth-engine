<?php

namespace App\Discovery;

/** Turns salesperson criteria into a small, deterministic set of source instructions. */
final class DiscoveryQueryPlanner
{
    public function plan(DiscoveryQuery $query): array
    {
        $criteria = $query->criteria;
        $location = trim((string) ($criteria['city'] ?? $criteria['location'] ?? ''));
        $country = trim((string) ($criteria['country'] ?? ''));
        $industry = mb_strtolower(trim((string) ($criteria['business_category'] ?? $criteria['industry'] ?? '')));
        $map = (array) config('discovery.osm_categories', []);
        $tags = $map[$industry] ?? ['shop' => ['*']];
        $words = array_values(array_unique(array_filter(array_map(
            static fn ($value) => mb_substr(trim((string) $value), 0, 80),
            is_array($criteria['keywords'] ?? null) ? $criteria['keywords'] : []
        ))));

        return [
            'location' => ['city' => $location ?: null, 'country' => $country ?: null, 'region' => $criteria['region'] ?? null,
                'radius_m' => min(max((int) ($criteria['radius_m'] ?? 10000), 500), (int) config('discovery.osm_max_radius_m', 25000))],
            'category' => $industry ?: 'business', 'tags' => $tags, 'keywords' => array_slice($words, 0, 8),
            'limit' => min(max(1, $query->limit), (int) config('discovery.osm_max_elements', 100)),
        ];
    }
}
