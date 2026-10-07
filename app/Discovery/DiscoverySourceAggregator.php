<?php

namespace App\Discovery;

use Throwable;

final class DiscoverySourceAggregator
{
    private array $failures = [];
    public function __construct(private readonly DiscoverySourceRegistry $sources) {}
    public function search(array $names, DiscoveryQuery $query): array
    {
        $this->failures = [];
        $results = [];
        foreach (array_slice(array_values(array_unique($names)), 0, (int) config('discovery.osm_max_queries_per_run', 3)) as $name) {
            try {
                $results = [...$results, ...$this->sources->get($name)->search($query)];
            } catch (Throwable $error) {
                $this->failures[$name] = mb_substr($error->getMessage(), 0, 240);
            }
        }
        return array_slice($results, 0, $query->limit);
    }
    public function failures(): array { return $this->failures; }
}
