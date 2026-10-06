<?php

namespace App\Jobs;

use App\Agents\AgentOrchestrator;
use App\Crawling\CrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScanWebsiteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2;
    public int $timeout = 360;
    public int $uniqueFor = 600;

    public function __construct(public string $tenantId, public string $websiteId, public string $scanId, public string $runId, public ?string $actorId)
    { $this->onQueue('crawl'); }

    public function uniqueId(): string { return $this->tenantId.':'.$this->scanId; }

    public function handle(CrawlerService $crawler, AgentOrchestrator $agents): void
    {
        $run = DB::table('agent_runs')->where('id', $this->runId)->where('tenant_id', $this->tenantId)->first();
        if (! $run || $run->status === 'succeeded' || $run->status === 'cancelled') return;

        $crawler->crawl($this->tenantId, $this->websiteId, existingScanId: $this->scanId);
        $agents->run('WebsiteIntelligenceAgent', $this->tenantId, ['website_scan_id' => $this->scanId], $this->actorId, $this->runId);
    }

    public function failed(?\Throwable $exception): void
    {
        $run = DB::table('agent_runs')->where('id', $this->runId)->where('tenant_id', $this->tenantId)->whereIn('status', ['queued', 'running'])->first();
        if (! $run) return;
        DB::table('agent_runs')->where('id', $this->runId)->where('tenant_id', $this->tenantId)
            ->update(['status' => 'failed', 'error_code' => 'WEBSITE_SCAN_FAILED', 'error_summary' => 'The website scan could not be completed.', 'finished_at' => now(), 'updated_at' => now()]);
        DB::table('agent_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'agent_run_id' => $this->runId,
            'sequence' => (int) DB::table('agent_events')->where('tenant_id', $this->tenantId)->where('agent_run_id', $this->runId)->max('sequence') + 1,
            'event_key' => 'failed', 'payload' => json_encode(['error_code' => 'WEBSITE_SCAN_FAILED', 'safe_message' => 'The website scan could not be completed.', 'correlation_id' => $run->correlation_id]), 'created_at' => now()]);
    }
}
