<?php

namespace App\Jobs;

use App\WebsiteResolution\CandidateDomainDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunCandidateDomainDiscoveryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 55;
    public int $uniqueFor = 900;

    public function __construct(public readonly string $tenantId, public readonly string $resolutionId)
    {
        $this->onQueue((string) config('candidate_discovery.queue', 'candidate-discovery'));
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->resolutionId; }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('candidate-domain-discovery:'.$this->tenantId))->releaseAfter(5)->expireAfter(70)];
    }

    public function handle(CandidateDomainDiscoveryService $discovery): void
    {
        $resolution = DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->first();
        if (! $resolution || ! in_array($resolution->state, ['PENDING', 'FAILED'], true)) return;
        if (! config('candidate_discovery.enabled', true)) {
            DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)->update([
                'state' => 'FAILED', 'discovery_status' => 'FAILED', 'failure_code' => 'DISCOVERY_DISABLED',
                'failure_summary' => 'Candidate-domain discovery is disabled by configuration.', 'finished_at' => now(), 'updated_at' => now()]);
            return;
        }
        $result = $discovery->discover($this->tenantId, $this->resolutionId);
        if (in_array($result['status'], ['CANDIDATES_FOUND', 'PARTIALLY_COMPLETED'], true) && $result['candidates'] > 0) {
            ResolveWebsiteCandidateJob::dispatch($this->tenantId, $this->resolutionId);
        }
    }

    public function failed(Throwable $error): void
    {
        DB::table('website_resolutions')->where('tenant_id', $this->tenantId)->where('id', $this->resolutionId)
            ->whereIn('state', ['PENDING', 'SEARCHING'])->update(['state' => 'FAILED', 'discovery_status' => 'FAILED',
                'failure_code' => 'DISCOVERY_JOB_FAILED', 'failure_summary' => 'Candidate discovery stopped unexpectedly. Retry is safe.',
                'finished_at' => now(), 'updated_at' => now()]);
    }
}
