<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\DB;

final class VerifiedDiscoveryIndexIngestionSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['verified_discovery_rows' => 0, 'directory_or_social_skipped' => 0];

    public function name(): string { return 'verified_discovery'; }
    public function metrics(): array { return $this->counts; }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $rows = DB::table('discovery_candidates as c')
            ->join('discovery_runs as r', function ($join): void { $join->on('r.id', '=', 'c.discovery_run_id')->on('r.tenant_id', '=', 'c.tenant_id'); })
            ->where('c.verification_state', 'verified')->whereNotNull('c.normalized_domain')
            ->whereIn('r.status', ['completed', 'succeeded', 'complete'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('discovery_candidate_sources as s')
                    ->whereColumn('s.tenant_id', 'c.tenant_id')->whereColumn('s.candidate_id', 'c.id')
                    ->whereIn('s.source', ['openstreetmap', 'wikidata']);
            })
            ->orderBy('c.id')->limit(min(1000, max(1, $limit + (int) ($cursor['offset'] ?? 0))))
            ->get(['c.id', 'c.normalized_domain', 'c.original_url', 'c.canonical_url', 'c.company_name', 'c.page_title', 'c.meta_description', 'c.country', 'c.city', 'c.industry', 'c.source', 'c.source_reference', 'c.discovered_at', 'c.updated_at']);
        $offset = max(0, (int) ($cursor['offset'] ?? 0));
        $rows = $rows->skip($offset)->take(min(500, max(1, $limit)));
        $position = $offset;
        foreach ($rows as $row) {
            $position++;
            $this->counts['verified_discovery_rows']++;
            $url = trim((string) ($row->canonical_url ?: $row->original_url));
            if ($url === '' || $row->company_name === null) continue;
            if (app(DirectoryDomainClassifier::class)->classify($url)) { $this->counts['directory_or_social_skipped']++; continue; }
            yield [
                'canonical_url' => $url, 'normalized_domain' => $row->normalized_domain,
                'page_title' => $row->page_title ?: $row->company_name, 'organization_name' => $row->company_name,
                'description' => $row->meta_description, 'visible_text_excerpt' => null,
                'country' => $row->country, 'city' => $row->city, 'source' => 'verified_open_discovery',
                'source_reference' => $row->source_reference ?: 'discovery-candidate:'.$row->id,
                'source_timestamp' => $row->discovered_at ?: $row->updated_at,
                'structured_data' => ['industry' => $row->industry, 'candidate_id' => $row->id, 'discovery_source' => $row->source],
                'source_query' => ['discovery_source' => $row->source], 'evidence_type' => 'verified_business_website',
                '_cursor' => ['offset' => $position],
            ];
        }
    }
}
