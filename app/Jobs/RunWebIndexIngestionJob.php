<?php

namespace App\Jobs;

use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunWebIndexIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $timeout = 390;

    public function __construct(public readonly string $runId) { $this->onQueue((string) config('website_resolution.index_ingestion_queue', 'web-index')); }

    public function middleware(): array { return [(new WithoutOverlapping('web-index:global'))->releaseAfter(30)->expireAfter(420)]; }

    public function handle(WebIndexIngestionSourceRegistry $sources, LocalWebIndexIngestionService $ingestion): void
    {
        $run = DB::table('web_index_ingestion_runs')->where('id', $this->runId)->first();
        if (! $run || in_array($run->status, ['completed', 'cancelled'], true)) return;
        $options = is_array($run->options) ? $run->options : (json_decode((string) $run->options, true) ?: []);
        $ingestion->ingest($sources->get($run->source), (int) $run->requested_limit, $options, $this->runId);
    }

    public function failed(Throwable $error): void
    {
        DB::table('web_index_ingestion_runs')->where('id', $this->runId)->whereNotIn('status', ['completed', 'partially_completed'])
            ->update(['status' => 'failed', 'failure_summary' => 'The bounded ingestion job failed. Resume from its last persisted checkpoint.', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
