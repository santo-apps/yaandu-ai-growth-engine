<?php

namespace App\Discovery;

use RuntimeException;

final class OpenStreetMapDiscoverySource implements DiscoverySourceInterface
{
    public function __construct(private readonly DiscoveryQueryPlanner $planner, private readonly LocationResolverInterface $locations, private readonly OverpassClient $overpass) {}
    public function name(): string { return 'openstreetmap'; }
    public function capabilities(): array { return ['location', 'business_poi', 'public_osm']; }

    public function search(DiscoveryQuery $query): array
    {
        $plan = $this->planner->plan($query);
        $location = $this->locations->resolve($plan['location']);
        if (! $location) throw new RuntimeException('This location is not in the configured open location dataset yet.');
        [$south, $west, $north, $east] = $location->bbox;
        $area = ($north - $south) * ($east - $west);
        if ($area <= 0 || $area > (float) config('discovery.osm_max_bbox_area_degrees', 0.25)) throw new RuntimeException('Location exceeds the configured search area.');

        $clauses = [];
        foreach ($plan['tags'] as $key => $values) {
            foreach (array_slice((array) $values, 0, 8) as $value) {
                $tag = $value === '*' ? '["'.addslashes($key).'"~".+"]' : '["'.addslashes($key).'"="'.addslashes((string) $value).'"]';
                $clauses[] = 'node'.$tag.'('.$south.','.$west.','.$north.','.$east.');';
                $clauses[] = 'way'.$tag.'('.$south.','.$west.','.$north.','.$east.');';
                $clauses[] = 'relation'.$tag.'('.$south.','.$west.','.$north.','.$east.');';
            }
        }
        if (! $clauses) throw new RuntimeException('No configured OpenStreetMap tags match this category.');
        $max = min($plan['limit'], (int) config('discovery.osm_max_elements', 100));
        $ql = '[out:json][timeout:'.min(25, (int) config('discovery.osm_timeout_seconds', 20)).'];('.implode('', $clauses).');out center tags '.$max.';';
        $cacheKey = json_encode([$location->label, $plan['category'], $plan['tags'], $max]);
        $elements = $this->overpass->query($ql, $cacheKey ?: '');
        $rows = [];
        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = trim((string) ($tags['name'] ?? ''));
            if ($name === '') continue;
            $website = trim((string) ($tags['website'] ?? $tags['contact:website'] ?? ''));
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);
            $type = (string) ($element['type'] ?? ''); $id = (string) ($element['id'] ?? '');
            if (! in_array($type, ['node', 'way', 'relation'], true) || ! ctype_digit($id)) continue;
            $rows[] = [
                'name' => mb_substr($name, 0, 255), 'website' => $website ?: null,
                'country' => $tags['addr:country'] ?? $location->country, 'city' => $tags['addr:city'] ?? $location->label,
                'industry' => $tags['shop'] ?? $tags['office'] ?? $tags['craft'] ?? $tags['amenity'] ?? $tags['tourism'] ?? $tags['healthcare'] ?? $plan['category'],
                'source' => $this->name(), 'source_reference' => $type.':'.$id,
                'source_timestamp' => $element['timestamp'] ?? null,
                'source_metadata' => ['osm_type' => $type, 'osm_id' => $id, 'lat' => $lat, 'lon' => $lon, 'tags' => $tags,
                    'query' => ['location' => $location->label, 'category' => $plan['category']],
                    'public_contact' => array_intersect_key($tags, array_flip(['phone','contact:phone','email','contact:email']))],
            ];
        }
        return array_slice($rows, 0, $max);
    }
}
