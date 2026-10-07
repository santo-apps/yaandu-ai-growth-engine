<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\DB;

final class WebsiteIdentitySnapshotFactory
{
    public function make(string $tenantId, object $candidate): array
    {
        $observations = DB::table('discovery_candidate_sources')->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidate->id)->orderBy('created_at')->get();
        $sources = $observations->map(function ($source): array {
            $metadata = is_array($source->source_metadata) ? $source->source_metadata : (json_decode((string) $source->source_metadata, true) ?: []);
            return ['source' => $source->source, 'reference' => $source->source_reference, 'observed_at' => $source->discovered_at,
                'metadata' => $metadata];
        })->all();
        $tags = [];
        foreach ($sources as $source) $tags = array_replace($tags, (array) ($source['metadata']['tags'] ?? []));
        $name = trim((string) ($candidate->company_name ?? ''));
        $identity = [
            'candidate_id' => (string) $candidate->id,
            'business_name' => $name ?: null,
            'normalized_business_name' => $this->normalizeName($name),
            'brand' => $tags['brand'] ?? null,
            'operator' => $tags['operator'] ?? null,
            'category' => $candidate->industry ?: null,
            'country' => $candidate->country ?: ($tags['addr:country'] ?? null),
            'region' => $tags['addr:state'] ?? $tags['addr:province'] ?? null,
            'city' => $candidate->city ?: ($tags['addr:city'] ?? null),
            'postcode' => $tags['addr:postcode'] ?? null,
            'address' => trim(implode(' ', array_filter([$tags['addr:housenumber'] ?? null, $tags['addr:street'] ?? null, $tags['addr:suburb'] ?? null]))),
            'latitude' => $tags['lat'] ?? ($sources[0]['metadata']['lat'] ?? null), 'longitude' => $tags['lon'] ?? ($sources[0]['metadata']['lon'] ?? null),
            'public_phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
            'public_email' => $tags['email'] ?? $tags['contact:email'] ?? null,
            'wikidata_id' => $tags['wikidata'] ?? null, 'wikipedia_id' => $tags['wikipedia'] ?? null,
            'brand_wikidata_id' => $tags['brand:wikidata'] ?? null, 'operator_wikidata_id' => $tags['operator:wikidata'] ?? null,
            'public_urls' => array_values(array_filter([$tags['website'] ?? null, $tags['contact:website'] ?? null, $tags['brand:website'] ?? null,
                $tags['operator:website'] ?? null, $tags['contact:facebook'] ?? null, $tags['contact:instagram'] ?? null,
                $tags['contact:linkedin'] ?? null, $tags['contact:youtube'] ?? null, $tags['contact:twitter'] ?? null])),
            'source_provenance' => $sources,
        ];
        return $identity;
    }

    public function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\pL\pN]+/u', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }
}
