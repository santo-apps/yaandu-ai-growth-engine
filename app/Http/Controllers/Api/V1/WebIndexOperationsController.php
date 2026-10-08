<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\RunWebIndexIngestionJob;
use App\WebsiteResolution\IndexQualityClassifier;
use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebIndexOperationsController
{
    public function overview(Request $request): array
    {
        $this->authorizeManager($request);
        $staleAfter = max(1, (int) config('website_resolution.refresh_stale_after_days', 45));
        $totals = DB::table('web_index_documents')->selectRaw("count(*) as documents, count(distinct normalized_domain) as domains,
            coalesce(sum(case when availability = 'available' and last_fetched_at >= ? then 1 else 0 end), 0) as fresh,
            coalesce(sum(case when availability <> 'available' or last_fetched_at is null or last_fetched_at < ? then 1 else 0 end), 0) as stale,
            coalesce(sum(stored_bytes), 0) as stored_bytes, max(indexed_at) as last_ingestion", [now()->subDays($staleAfter), now()->subDays($staleAfter)])->first();
        $sources = DB::table('web_index_document_sources as s')->join('web_index_documents as d', 'd.id', '=', 's.document_id')
            ->selectRaw('s.source, count(*) as observations, count(distinct s.document_id) as documents, count(distinct d.normalized_domain) as domains,
                coalesce(avg(d.identity_completeness), 0) as identity_completeness,
                coalesce(sum(case when d.has_public_contact then 1 else 0 end), 0) as contact_documents,
                coalesce(sum(case when d.has_structured_data then 1 else 0 end), 0) as structured_documents')
            ->groupBy('s.source')->orderBy('s.source')->get()->keyBy('source');
        $runMetrics = DB::table('web_index_ingestion_runs')->orderByDesc('started_at')->limit(1000)->get(['source', 'processed', 'inserted', 'updated', 'unchanged', 'failed', 'source_failures', 'duplicates', 'unique_domains_added', 'metrics']);
        foreach ($runMetrics as $run) {
            if (! isset($sources[$run->source])) $sources[$run->source] = (object) ['source' => $run->source, 'observations' => 0, 'documents' => 0,
                'domains' => 0, 'identity_completeness' => 0, 'contact_documents' => 0, 'structured_documents' => 0];
            $metrics = is_array($run->metrics) ? $run->metrics : (json_decode((string) $run->metrics, true) ?: []);
            $source = $sources[$run->source];
            $source->attempted = (int) ($source->attempted ?? 0) + (int) ($metrics['explicit_website_refs'] ?? $metrics['p856_urls'] ?? $metrics['domains_considered'] ?? $metrics['verified_discovery_rows'] ?? $metrics['due_documents'] ?? $run->processed);
            $source->indexed = (int) ($source->indexed ?? 0) + (int) $run->inserted + (int) $run->updated + (int) $run->unchanged;
            $source->new_domains = (int) ($source->new_domains ?? 0) + (int) $run->unique_domains_added;
            $source->failures = (int) ($source->failures ?? 0) + (int) $run->failed
                + max((int) $run->source_failures, (int) ($metrics['source_failures'] ?? 0));
            $source->duplicates = (int) ($source->duplicates ?? 0) + (int) $run->duplicates;
        }
        $sources = $sources->map(function ($source): array {
            $attempted = (int) ($source->attempted ?? 0);
            $indexed = (int) ($source->indexed ?? 0);
            $yield = $attempted > 0 ? min(100, 100 * $indexed / $attempted) : 0;
            $domainYield = $attempted > 0 ? min(100, 100 * (int) ($source->new_domains ?? 0) / $attempted) : 0;
            $completeness = (float) $source->identity_completeness;
            $source->quality_score = (int) round(($yield * 0.5) + ($domainYield * 0.3) + ($completeness * 0.2));
            $source->successful_index_percent = round($yield, 1);
            $source->unique_domain_yield_percent = round($domainYield, 1);
            return (array) $source;
        })->values();
        $quality = DB::table('web_index_documents')->selectRaw("count(distinct normalized_domain) as total,
            count(distinct case when organization_name is not null and organization_name <> '' then normalized_domain end) as with_name,
            count(distinct case when city is not null or country is not null then normalized_domain end) as with_location,
            count(distinct case when has_public_contact then normalized_domain end) as with_contact,
            count(distinct case when has_structured_data then normalized_domain end) as with_structured_data,
            coalesce(avg(identity_completeness), 0) as average_completeness")->first();
        $qualityClasses = $this->qualityClassifications();
        $countryDistribution = DB::table('web_index_documents')->selectRaw('country as label, count(distinct normalized_domain) as domains')
            ->whereNotNull('country')->groupBy('country')->orderByDesc('domains')->limit(20)->get();
        $cityDistribution = DB::table('web_index_documents')->selectRaw('city as label, count(distinct normalized_domain) as domains')
            ->whereNotNull('city')->groupBy('city')->orderByDesc('domains')->limit(20)->get();
        $categoryDistribution = DB::table('web_index_documents')->selectRaw('business_category as label, count(distinct normalized_domain) as domains')
            ->whereNotNull('business_category')->groupBy('business_category')->orderByDesc('domains')->limit(25)->get();
        $runs = DB::table('web_index_ingestion_runs')->orderByDesc('started_at')->limit(20)
            ->get(['id', 'source', 'status', 'processed', 'inserted', 'updated', 'unchanged', 'failed', 'source_failures', 'duplicates',
                'unique_domains_added', 'bytes_processed', 'stored_bytes_delta', 'started_at', 'finished_at', 'checkpointed_at', 'failure_summary', 'metrics'])
            ->map(function ($run): array { $run->metrics = is_array($run->metrics) ? $run->metrics : (json_decode((string) $run->metrics, true) ?: []); return (array) $run; });
        $active = DB::table('web_index_ingestion_runs')->whereIn('status', ['pending', 'running', 'paused'])->count();
        $lastIngestion = DB::table('web_index_ingestion_runs')->max('started_at');
        return ['corpus' => ['documents' => (int) $totals->documents, 'domains' => (int) $totals->domains, 'fresh' => (int) $totals->fresh,
            'stale' => (int) $totals->stale, 'last_ingestion' => $lastIngestion, 'active_runs' => $active, 'stored_bytes' => (int) $totals->stored_bytes],
            'sources' => $sources, 'runs' => $runs,
            'quality' => ['domains_with_name' => (int) $quality->with_name, 'domains_with_location' => (int) $quality->with_location,
                'domains_with_public_contact' => (int) $quality->with_contact, 'domains_with_structured_data' => (int) $quality->with_structured_data,
                'average_identity_completeness' => round((float) $quality->average_completeness, 1), 'quality_domains' => $qualityClasses['HIGH_QUALITY_BUSINESS'] ?? 0,
                'classification' => $qualityClasses],
            'distribution' => ['countries' => $countryDistribution, 'cities' => $cityDistribution, 'categories' => $categoryDistribution],
            'plans' => ['locations' => array_map(fn ($entry, $name) => ['name' => $name, 'country' => $entry['country'] ?? ''], (array) config('discovery.locations', []), array_keys((array) config('discovery.locations', []))),
                'categories' => array_keys((array) config('discovery.osm_categories', []))]];
    }

    public function candidateDiscovery(Request $request): array
    {
        $this->authorizeManager($request);
        $tenant = app('tenant.id');
        $resolutions = DB::table('website_resolutions')->where('tenant_id', $tenant);
        $states = (clone $resolutions)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $attemptRows = DB::table('website_resolution_attempts')->where('tenant_id', $tenant)
            ->selectRaw("source, state, count(*) as calls, sum(case when state in ('failed','partial') then 1 else 0 end) as failures")
            ->groupBy('source', 'state')->orderBy('source')->get();
        $sources = $attemptRows->groupBy('source')->map(fn ($rows, $source): array => [
            'source' => $source,
            'calls' => (int) $rows->sum('calls'),
            'failures' => (int) $rows->sum('failures'),
        ])->values();
        $runRows = (clone $resolutions)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'candidate_id', 'state', 'discovery_status', 'failure_code', 'failure_summary', 'discovery_metrics', 'created_at', 'finished_at']);
        $latencies = [];
        $cacheHits = 0;
        $sourceCalls = 0;
        $sourceFailures = 0;
        $providerCalls = 0;
        $queryCount = 0;
        foreach ($runRows as $run) {
            $metrics = is_array($run->discovery_metrics) ? $run->discovery_metrics : (json_decode((string) $run->discovery_metrics, true) ?: []);
            $run->discovery_metrics = $metrics;
            $cacheHits += (int) ($metrics['cache_hits'] ?? 0);
            $sourceCalls += (int) ($metrics['source_calls'] ?? 0);
            $sourceFailures += (int) ($metrics['source_failures'] ?? 0);
            $providerCalls += (int) ($metrics['provider_calls'] ?? 0);
            $queryCount += (int) ($metrics['queries'] ?? 0);
            if (isset($metrics['latency_ms'])) $latencies[] = (int) $metrics['latency_ms'];
            $run->business_name = DB::table('discovery_candidates')->where('tenant_id', $tenant)->where('id', $run->candidate_id)->value('company_name');
            $run->candidate_count = DB::table('website_resolution_candidates')->where('tenant_id', $tenant)->where('resolution_id', $run->id)->count();
        }
        sort($latencies);
        $count = count($latencies);
        $middle = intdiv($count, 2);
        $median = $count ? ($count % 2 ? $latencies[$middle] : (int) round(($latencies[$middle - 1] + $latencies[$middle]) / 2)) : null;
        $p95 = $count ? $latencies[max(0, (int) ceil($count * .95) - 1)] : null;
        $failureBreakdown = (clone $resolutions)->whereNotNull('failure_code')->selectRaw('failure_code, count(*) as total')->groupBy('failure_code')->orderByDesc('total')->limit(20)->get();
        $resultCount = DB::table('website_resolution_search_results')->where('tenant_id', $tenant)->count();
        return [
            'totals' => ['runs' => (clone $resolutions)->count(), 'candidate_domains' => DB::table('website_resolution_candidates')->where('tenant_id', $tenant)->count(),
                'raw_results' => $resultCount, 'source_calls_last_50' => $sourceCalls, 'source_failures_last_50' => $sourceFailures,
                'queries_last_50' => $queryCount, 'cache_hits_last_50' => $cacheHits, 'provider_calls_last_50' => $providerCalls,
                'cache_hit_percent_last_50' => $providerCalls ? round(100 * $cacheHits / ($cacheHits + $providerCalls), 1) : 0],
            'states' => $states, 'sources' => $sources, 'failure_breakdown' => $failureBreakdown,
            'latency' => ['median_ms_last_50' => $median, 'p95_ms_last_50' => $p95, 'sample_size' => $count], 'runs' => $runRows,
        ];
    }

    /** @return array<string,int> */
    private function qualityClassifications(): array
    {
        $counts = array_fill_keys(IndexQualityClassifier::CLASSES, 0);
        DB::table('web_index_documents')->select(['id', 'canonical_url', 'normalized_domain', 'document_type', 'page_title', 'description',
            'visible_text_excerpt', 'organization_name', 'city', 'country', 'has_public_contact', 'has_structured_data', 'availability'])
            ->orderBy('id')->chunkById(500, function ($documents) use (&$counts): void {
                $ids = $documents->pluck('id')->all();
                $evidence = DB::table('web_index_document_sources')->whereIn('document_id', $ids)->get(['document_id', 'evidence_type'])
                    ->groupBy('document_id')->map(fn ($rows) => $rows->pluck('evidence_type')->all());
                $classifier = app(IndexQualityClassifier::class);
                foreach ($documents as $document) {
                    $classification = $classifier->classify($document, $evidence->get($document->id, []));
                    $counts[$classification] = ($counts[$classification] ?? 0) + 1;
                }
            });
        return $counts;
    }

    public function start(Request $request, WebIndexIngestionSourceRegistry $sources, LocalWebIndexIngestionService $ingestion): array
    {
        $this->authorizeManager($request);
        $data = $request->validate(['source' => ['required', 'string', 'in:verified_discovery,osm_public_websites,wikidata_linked_websites,common_crawl_known_domains'],
            'limit' => ['required', 'integer', 'min:1', 'max:500'], 'location' => ['nullable', 'string', 'max:120'],
            'categories' => ['nullable', 'array', 'max:8'], 'categories.*' => ['string', 'max:64'],
            'max_bytes' => ['nullable', 'integer', 'min:1024', 'max:250000000'], 'max_runtime_seconds' => ['nullable', 'integer', 'min:10', 'max:3600']]);
        $source = $sources->get($data['source']);
        $options = ['max_source_records' => $data['limit'], 'max_domains' => $data['limit'],
            'max_bytes' => $data['max_bytes'] ?? (int) config('website_resolution.index_ingestion_max_bytes', 250_000_000),
            'max_runtime_seconds' => $data['max_runtime_seconds'] ?? (int) config('website_resolution.index_ingestion_max_duration_seconds', 300),
            'max_failures' => (int) config('website_resolution.index_ingestion_max_failures', 50)];
        if ($data['source'] === 'osm_public_websites') {
            $location = (string) ($data['location'] ?? ''); $locations = (array) config('discovery.locations', []);
            if (! isset($locations[$location])) throw ValidationException::withMessages(['location' => ['Choose a configured ingestion region.']]);
            $categories = $data['categories'] ?? ['business'];
            foreach ($categories as $category) if (! array_key_exists(mb_strtolower($category), (array) config('discovery.osm_categories', []))) {
                throw ValidationException::withMessages(['categories' => ['Choose only configured OpenStreetMap categories.']]);
            }
            $options['location'] = $location; $options['country'] = $locations[$location]['country']; $options['categories'] = $categories;
            $options['max_fetches'] = min(1000, max(1, (int) config('website_resolution.osm_index_max_fetch_attempts', 250)));
        }
        $runId = $ingestion->createRun($source, (int) $data['limit'], $options);
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'actor_user_id' => $request->user()->id,
            'action' => 'web_index_ingestion_queued', 'subject_type' => 'web_index_ingestion_run', 'subject_id' => $runId,
            'metadata' => json_encode(['source' => $source->name(), 'limit' => $data['limit'], 'location' => $options['location'] ?? null, 'categories' => $options['categories'] ?? []]), 'created_at' => now()]);
        RunWebIndexIngestionJob::dispatch($runId);
        return ['run_id' => $runId, 'status' => 'pending', 'queue' => (string) config('website_resolution.index_ingestion_queue', 'web-index')];
    }

    private function authorizeManager(Request $request): void
    {
        $allowed = $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivotIn('role', ['owner', 'admin'])
            ->wherePivot('status', 'active')->where('tenants.status', 'active')->exists();
        abort_unless($allowed, 403, 'Corpus operations require an active tenant owner or administrator.');
    }
}
