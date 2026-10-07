<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WebIndexStatus extends Command
{
    protected $signature = 'web-index:status';
    protected $description = 'Show local website index coverage and provenance metrics.';

    public function handle(): int
    {
        $staleAfter = max(1, (int) config('website_resolution.refresh_stale_after_days', 45));
        $totals = DB::table('web_index_documents')->selectRaw("count(*) as documents, count(distinct normalized_domain) as domains, max(indexed_at) as last_ingestion,
            coalesce(sum(case when availability = 'available' and last_fetched_at >= ? then 1 else 0 end), 0) as fresh,
            coalesce(sum(case when availability <> 'available' or last_fetched_at is null or last_fetched_at < ? then 1 else 0 end), 0) as stale,
            coalesce(sum(stored_bytes), 0) as stored_bytes", [now()->subDays($staleAfter), now()->subDays($staleAfter)])->first();
        $sources = DB::table('web_index_document_sources as s')->join('web_index_documents as d', 'd.id', '=', 's.document_id')
            ->selectRaw('s.source, count(*) as observations, count(distinct s.document_id) as documents, count(distinct d.normalized_domain) as domains,
                coalesce(avg(d.identity_completeness), 0) as average_identity_completeness,
                coalesce(sum(case when d.has_public_contact then 1 else 0 end), 0) as public_contact_documents,
                coalesce(sum(case when d.has_structured_data then 1 else 0 end), 0) as structured_data_documents')
            ->groupBy('s.source')->orderBy('s.source')->get();
        $this->line('Documents indexed: '.(int) $totals->documents);
        $this->line('Domains indexed: '.(int) $totals->domains);
        $this->line('Last indexed document: '.($totals->last_ingestion ?: 'never'));
        $this->line('Fresh documents: '.(int) $totals->fresh.' / stale or unavailable: '.(int) $totals->stale);
        $this->line('Stored metadata/excerpt bytes: '.(int) $totals->stored_bytes);
        $lastRun = DB::table('web_index_ingestion_runs')->orderByDesc('started_at')->first();
        $failed = (int) DB::table('web_index_ingestion_runs')->sum('failed') + (int) DB::table('web_index_ingestion_runs')->sum('source_failures');
        $this->line('Last ingestion: '.($lastRun ? $lastRun->source.' / '.$lastRun->status.' / '.$lastRun->started_at : 'never'));
        $this->line('Recorded ingestion failures: '.$failed);
        if ($lastRun && $lastRun->metrics) $this->line('Last source metrics: '.$lastRun->metrics);
        $active = DB::table('web_index_ingestion_runs')->whereIn('status', ['pending', 'running', 'paused'])->orderBy('started_at')->first();
        $this->line('Active ingestion: '.($active ? $active->id.' / '.$active->source.' / '.$active->status : 'none'));
        $recentFailures = DB::table('web_index_ingestion_runs')->where('failed', '>', 0)->orderByDesc('started_at')->limit(5)
            ->get(['source', 'status', 'failed', 'source_failures', 'started_at']);
        $this->table(['source', 'observations', 'documents', 'domains', 'identity quality', 'public contact', 'structured data'],
            $sources->map(fn ($row) => [$row->source, $row->observations, $row->documents, $row->domains,
                round((float) $row->average_identity_completeness, 1), $row->public_contact_documents, $row->structured_data_documents])->all());
        if ($recentFailures->isNotEmpty()) $this->table(['recent failed run', 'status', 'document failures', 'source failures', 'started'], $recentFailures->map(fn ($row) => [$row->source, $row->status, $row->failed, $row->source_failures, $row->started_at])->all());
        return self::SUCCESS;
    }
}
