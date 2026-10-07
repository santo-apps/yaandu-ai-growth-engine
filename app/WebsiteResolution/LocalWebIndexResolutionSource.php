<?php

namespace App\WebsiteResolution;

final class LocalWebIndexResolutionSource implements WebsiteResolutionSourceInterface
{
    public function __construct(private readonly LocalWebIndexSearchService $search) {}

    public function name(): string { return 'local_web_index'; }

    public function find(array $identity, array $knownCandidates = []): array
    {
        $found = [];
        foreach ($this->search->search($identity, (int) config('website_resolution.local_index_results_per_business', 10)) as $result) {
            $document = $result['document'];
            $isDirectory = ($document->document_type ?? 'business') === 'directory';
            $targets = $isDirectory ? (array) (json_decode((string) $document->outbound_business_links, true) ?: []) : [[
                'url' => $document->canonical_url,
                'organization_name' => $document->organization_name,
                'city' => $document->city,
                'country' => $document->country,
                'phone' => (json_decode((string) $document->phone_values, true) ?: [])[0] ?? null,
            ]];
            foreach ($targets as $target) {
                $url = is_string($target) ? $target : (string) ($target['url'] ?? '');
                if ($url === '') continue;
                if ($isDirectory) {
                    try {
                        $targetDomain = app(\App\Discovery\DomainNormalizer::class)->normalize($url)['normalized_domain'];
                        if ($targetDomain === $document->normalized_domain) continue;
                    } catch (\Throwable) { continue; }
                }
                $provenance = ['document_id' => $document->id, 'canonical_url' => $document->canonical_url,
                    'normalized_domain' => $document->normalized_domain, 'source' => $document->source,
                    'source_reference' => $document->source_reference, 'source_timestamp' => $document->source_timestamp,
                    'indexed_at' => $document->indexed_at, 'content_hash' => $document->content_hash,
                    'availability' => $document->availability ?? 'available', 'last_fetched_at' => $document->last_fetched_at ?? $document->indexed_at,
                    'source_observations' => \Illuminate\Support\Facades\DB::table('web_index_document_sources')->where('document_id', $document->id)
                        ->orderBy('source')->get(['source', 'source_reference', 'source_query', 'evidence_type', 'source_timestamp', 'first_observed_at', 'last_observed_at'])
                        ->map(function ($row): array { $row->source_query = is_array($row->source_query) ? $row->source_query : (json_decode((string) $row->source_query, true) ?: []); return (array) $row; })->all()];
                $evidence = [[
                    'signal' => 'local_index_discovery', 'polarity' => 'neutral', 'points' => 0,
                    'summary' => 'A bounded local index returned this public URL as a discovery candidate; search rank is not identity confidence.',
                    'source' => $this->name(),
                    'source_reference' => (string) ($document->source_reference ?: $document->canonical_url),
                    'details' => ['search_rank' => $result['search_rank'], 'matching_fields' => $result['matching_fields'],
                        'index_provenance' => $provenance],
                ]];
                if ($isDirectory) {
                    $evidence[] = ['signal' => 'directory_business_link', 'polarity' => 'positive', 'points' => 0,
                        'summary' => 'A public directory page links this business identity to the candidate URL; the directory host is not proposed as the official website.',
                        'source' => $this->name(), 'source_reference' => (string) ($document->source_reference ?: $document->canonical_url),
                        'details' => ['directory_url' => $document->canonical_url, 'linked_name' => is_array($target) ? ($target['organization_name'] ?? null) : null,
                            'linked_city' => is_array($target) ? ($target['city'] ?? null) : null, 'linked_country' => is_array($target) ? ($target['country'] ?? null) : null,
                            'linked_phone' => is_array($target) ? ($target['phone'] ?? null) : null]];
                }
                $found[] = ['url' => $url, 'source' => $this->name(), 'candidate_type' => $isDirectory ? 'directory_link' : 'business',
                    'source_reference' => (string) ($document->source_reference ?: $document->canonical_url), 'search_rank' => $result['search_rank'],
                    'matching_fields' => $result['matching_fields'], 'index_provenance' => [$provenance], 'evidence' => $evidence];
            }
        }
        return $this->aggregateDomains($found);
    }

    private function aggregateDomains(array $rows): array
    {
        $aggregated = [];
        foreach ($rows as $row) {
            try { $domain = app(\App\Discovery\DomainNormalizer::class)->normalize($row['url'])['normalized_domain']; }
            catch (\Throwable) { continue; }
            if (! isset($aggregated[$domain])) { $row['existing_domain'] = $domain; $aggregated[$domain] = $row; continue; }
            $aggregated[$domain]['evidence'] = [...$aggregated[$domain]['evidence'], ...$row['evidence']];
            $aggregated[$domain]['search_rank'] = max($aggregated[$domain]['search_rank'], $row['search_rank']);
            $aggregated[$domain]['matching_fields'] = array_values(array_unique([...$aggregated[$domain]['matching_fields'], ...$row['matching_fields']]));
            $aggregated[$domain]['index_provenance'] = [...$aggregated[$domain]['index_provenance'], ...$row['index_provenance']];
            if (($aggregated[$domain]['candidate_type'] ?? '') !== 'business' && ($row['candidate_type'] ?? '') === 'business') {
                $aggregated[$domain]['url'] = $row['url'];
                $aggregated[$domain]['candidate_type'] = 'business';
            }
        }
        return array_values($aggregated);
    }
}
