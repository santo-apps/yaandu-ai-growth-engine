<?php

namespace App\WebsiteResolution;

use Throwable;

final class WikidataWebsiteResolutionSource implements WebsiteResolutionSourceInterface
{
    public function __construct(private readonly WikidataClient $client) {}
    public function name(): string { return 'wikidata'; }

    public function find(array $identity, array $knownCandidates = []): array
    {
        $references = [];
        foreach ([['wikidata_id', 'direct'], ['operator_wikidata_id', 'operator'], ['brand_wikidata_id', 'brand']] as [$field, $kind]) {
            $id = (string) ($identity[$field] ?? '');
            if ($id !== '') $references[$id] = $kind;
        }
        foreach ((array) ($identity['source_provenance'] ?? []) as $source) {
            $tags = (array) ($source['metadata']['tags'] ?? []);
            foreach (['wikidata' => 'direct', 'operator:wikidata' => 'operator', 'brand:wikidata' => 'brand'] as $tag => $kind) {
                $id = (string) ($tags[$tag] ?? '');
                if ($id !== '' && (! isset($references[$id]) || $references[$id] === 'brand' || $kind === 'direct')) $references[$id] = $kind;
            }
        }
        $references = array_slice(array_filter($references, fn ($kind, $id) => preg_match('/^Q[1-9][0-9]{0,11}$/', (string) $id), ARRAY_FILTER_USE_BOTH), 0, 3, true);
        $out = [];
        foreach ($references as $id => $kind) {
            try { $urls = $this->client->officialWebsites($id); } catch (Throwable) { throw new \RuntimeException('Wikidata source unavailable.'); }
            $candidateType = match ($kind) { 'brand' => 'brand_reference', 'operator' => 'operator_reference', default => 'official_reference' };
            $signal = match ($kind) { 'brand' => 'wikidata_brand_website', 'operator' => 'wikidata_operator_website', default => 'wikidata_official_website' };
            $points = (int) config('website_resolution.score.'.$signal, match ($kind) { 'brand' => 30, 'operator' => 40, default => 85 });
            foreach ($urls as $url) $out[] = ['url' => $url, 'source' => $this->name(), 'candidate_type' => $candidateType,
                'source_reference' => $id, 'evidence' => [['signal' => $signal, 'polarity' => 'positive', 'points' => $points,
                    'summary' => 'The linked Wikidata entity lists this value as its official website (P856); the OSM reference type controls its match weight.', 'details' => ['entity_id' => $id, 'osm_reference_type' => $kind]]]];
        }
        return $out;
    }
}
