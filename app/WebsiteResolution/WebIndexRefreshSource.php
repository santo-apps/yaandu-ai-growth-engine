<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\DB;
use Throwable;

final class WebIndexRefreshSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['due_documents' => 0, 'refreshed' => 0, 'unchanged_or_changed' => 0, 'failures' => 0];

    public function __construct(private readonly PublicWebsiteDocumentFetcher $fetcher) {}
    public function name(): string { return 'web_index_refresh'; }
    public function metrics(): array { return $this->counts; }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $query = DB::table('web_index_documents')->where(function ($builder): void {
            $builder->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', now());
        })->orderBy('id')->limit(min(500, max(1, $limit)));
        if ($cursor && isset($cursor['document_id'])) $query->where('id', '>', $cursor['document_id']);
        foreach ($query->get(['id', 'canonical_url', 'organization_name', 'city', 'country', 'normalized_domain']) as $row) {
            $this->counts['due_documents']++;
            try {
                $document = $this->fetcher->fetch($row->canonical_url, ['organization_name' => $row->organization_name, 'city' => $row->city, 'country' => $row->country]);
                $this->counts['refreshed']++;
                $document += ['source' => $this->name(), 'source_reference' => 'refresh:'.$row->id, 'source_query' => ['document_id' => $row->id],
                    'evidence_type' => 'refresh_observation', 'source_timestamp' => now()->toIso8601String(), '_cursor' => ['document_id' => $row->id]];
                yield $document;
            } catch (Throwable $error) {
                $code = match (true) {
                    str_contains($error->getMessage(), 'ROBOTS_DENIED') => 'ROBOTS_DENIED',
                    str_contains($error->getMessage(), 'ROBOTS_UNAVAILABLE') => 'ROBOTS_UNAVAILABLE',
                    str_contains($error->getMessage(), 'UNSAFE_REDIRECT') => 'UNSAFE_REDIRECT',
                    str_contains($error->getMessage(), 'DNS_FAILURE') => 'DNS_FAILURE',
                    str_contains($error->getMessage(), 'TIMEOUT') => 'TIMEOUT',
                    str_contains($error->getMessage(), 'URL_NORMALIZATION_REJECTED') => 'UNSAFE_URL',
                    str_contains($error->getMessage(), 'UNSAFE_URL') => 'UNSAFE_URL',
                    str_contains($error->getMessage(), 'NON_HTML') => 'NON_HTML',
                    str_contains($error->getMessage(), 'OVERSIZED') => 'OVERSIZED',
                    str_contains($error->getMessage(), 'PARSE_FAILURE') => 'PARSE_FAILURE',
                    default => 'TRANSPORT_FAILURE',
                };
                $this->counts['failures']++;
                yield ['_failure' => $code, '_document_id' => $row->id, '_cursor' => ['document_id' => $row->id]];
            }
        }
    }
}
