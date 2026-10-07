<?php

namespace App\Console\Commands;

use App\Jobs\RunWebIndexIngestionJob;
use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RefreshWebIndex extends Command
{
    protected $signature = 'web-index:refresh {--limit=100 : Maximum due pages to inspect, 1-500} {--dry-run : Preview without fetching or writing} {--queue : Dispatch to the isolated web-index queue}';
    protected $description = 'Refresh due public web-index documents using the normal URL and robots safety policy.';

    public function handle(WebIndexIngestionSourceRegistry $sources, LocalWebIndexIngestionService $ingestion): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) { $this->error('Specify --limit between 1 and 500.'); return self::INVALID; }
        $source = $sources->get('web_index_refresh');
        $due = DB::table('web_index_documents')->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', now())->count();
        if ($this->option('dry-run')) {
            $this->table(['source', 'due pages', 'selected upper bound', 'side effects'], [['web_index_refresh', $due, min($due, $limit), 'none']]);
            return self::SUCCESS;
        }
        $options = ['max_runtime_seconds' => min(3600, max(10, (int) config('website_resolution.index_ingestion_max_duration_seconds', 300))),
            'max_bytes' => (int) config('website_resolution.index_ingestion_max_bytes', 250_000_000)];
        if ($this->option('queue')) {
            $runId = $ingestion->createRun($source, $limit, $options);
            RunWebIndexIngestionJob::dispatch($runId);
            $this->info("Queued bounded refresh run {$runId}; {$due} documents are currently due.");
            return self::SUCCESS;
        }
        try { $result = $ingestion->ingest($source, $limit, $options); }
        catch (Throwable) { $this->error('Refresh failed safely; the run checkpoint is persisted.'); return self::FAILURE; }
        $this->table(['run', 'due pages', 'refreshed', 'failures', 'status'], [[$result['run_id'], $due,
            $result['source_metrics']['refreshed'] ?? 0, $result['source_metrics']['failures'] ?? 0, $result['status']]]);
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
