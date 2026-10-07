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
        $totals = DB::table('web_index_documents')->selectRaw('count(*) as documents, count(distinct normalized_domain) as domains, max(indexed_at) as last_ingestion')->first();
        $sources = DB::table('web_index_documents')->selectRaw('source, count(*) as documents, count(distinct normalized_domain) as domains')->groupBy('source')->orderBy('source')->get();
        $this->line('Documents indexed: '.(int) $totals->documents);
        $this->line('Domains indexed: '.(int) $totals->domains);
        $this->line('Last indexed document: '.($totals->last_ingestion ?: 'never'));
        $lastRun = DB::table('web_index_ingestion_runs')->orderByDesc('started_at')->first();
        $failed = (int) DB::table('web_index_ingestion_runs')->sum('failed') + (int) DB::table('web_index_ingestion_runs')->sum('source_failures');
        $this->line('Last ingestion: '.($lastRun ? $lastRun->source.' / '.$lastRun->status.' / '.$lastRun->started_at : 'never'));
        $this->line('Recorded ingestion failures: '.$failed);
        if ($lastRun && $lastRun->metrics) $this->line('Last source metrics: '.$lastRun->metrics);
        $this->table(['source', 'documents', 'domains'], $sources->map(fn ($row) => [$row->source, $row->documents, $row->domains])->all());
        return self::SUCCESS;
    }
}
