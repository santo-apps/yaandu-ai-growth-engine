<?php

namespace App\WebsiteResolution;

final class CandidateDomainQueryPlanner
{
    /** @return list<string> */
    public function plan(BusinessIdentity $identity): array
    {
        if (! $identity->hasMinimumDiscoveryQuality()) return [];
        $name = $this->phrase($identity->businessName);
        $queries = [];
        $add = static function (array &$target, array $parts) use (&$queries): void {
            $parts = array_values(array_filter(array_map('trim', $parts)));
            if ($parts === []) return;
            $query = implode(' ', array_map(static fn (string $part): string => '"'.$part.'"', $parts));
            if (! in_array($query, $target, true)) $target[] = $query;
        };
        $add($queries, [$name, $identity->city]);
        $add($queries, [$name, $identity->city, $identity->category]);
        $add($queries, [$name, $identity->country]);
        $add($queries, [$name, $identity->region, $identity->category]);
        if ($identity->publicPhone) $add($queries, [$name, $identity->publicPhone]);
        foreach (array_slice($identity->alternateNames, 0, 2) as $alternate) $add($queries, [$alternate, $identity->city ?: $identity->country]);

        return array_slice($queries, 0, min(5, max(1, (int) config('candidate_discovery.max_queries_per_business', 5))));
    }

    private function phrase(string $value): string
    {
        $value = preg_replace('/[\pC\pZ]+/u', ' ', trim($value)) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, 120);
    }
}
