<?php

namespace App\WebsiteResolution;

final class OSMWebsiteEvidenceSource implements WebsiteResolutionSourceInterface
{
    public function name(): string { return 'osm_website_evidence'; }

    public function find(array $identity, array $knownCandidates = []): array
    {
        $out = [];
        foreach ((array) ($identity['source_provenance'] ?? []) as $observation) {
            $metadata = (array) ($observation['metadata'] ?? []);
            if (($observation['source'] ?? null) !== 'openstreetmap') continue;
            $tags = (array) ($metadata['tags'] ?? []);
            foreach ([
                'website' => ['direct_osm_website', 100, 'direct'],
                'contact:website' => ['direct_osm_contact_website', 95, 'direct'],
                'brand:website' => ['brand_website', 35, 'brand'],
                'operator:website' => ['operator_website', 30, 'operator'],
            ] as $tag => [$signal, $points, $kind]) {
                $url = trim((string) ($tags[$tag] ?? ''));
                if ($url === '') continue;
                $out[] = ['url' => $url, 'source' => $this->name(), 'candidate_type' => $kind,
                    'source_reference' => (string) ($observation['reference'] ?? ''),
                    'evidence' => [['signal' => $signal, 'polarity' => 'positive', 'points' => $points,
                        'summary' => $tag.' is present on the public OSM business object.', 'details' => ['tag' => $tag]]]];
            }
            foreach (['brand:wikidata' => 'brand_wikidata_reference', 'operator:wikidata' => 'operator_wikidata_reference',
                'wikidata' => 'wikidata_reference', 'wikipedia' => 'wikipedia_reference'] as $tag => $signal) {
                if (! empty($tags[$tag])) $out[] = ['url' => null, 'source' => $this->name(), 'candidate_type' => 'identity_reference',
                    'source_reference' => (string) ($observation['reference'] ?? ''),
                    'evidence' => [['signal' => $signal, 'polarity' => 'positive', 'points' => 0,
                        'summary' => $tag.' identity reference is available for lookup.', 'details' => ['tag' => $tag, 'value' => $tags[$tag]]]]];
            }
        }
        return $out;
    }
}
