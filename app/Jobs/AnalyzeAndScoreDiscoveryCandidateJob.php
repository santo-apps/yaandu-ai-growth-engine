<?php

namespace App\Jobs;

use App\Agents\AgentOrchestrator;
use App\Crawling\CrawlerService;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AnalyzeAndScoreDiscoveryCandidateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 360;
    public int $uniqueFor = 900;

    public function __construct(public string $tenantId, public string $candidateId, public ?string $actorId = null, public string $stage = 'all')
    {
        $this->onQueue($stage === 'scoring' ? 'scoring' : 'crawl');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->candidateId; }

    public function handle(CrawlerService $crawler, AgentOrchestrator $agents): void
    {
        $candidate = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $this->candidateId)->first();
        if (! $candidate || $candidate->lifecycle_status === 'accepted' || $candidate->verification_state !== 'verified') return;
        $company = Company::where('tenant_id', $this->tenantId)->where('id', $candidate->company_id)->where('status', 'discovery_candidate')->first();
        if (! $company) throw new RuntimeException('The verified candidate is not in pre-promotion review state.');
        $website = DB::table('company_websites')->where('tenant_id', $this->tenantId)->where('company_id', $company->id)->orderByDesc('created_at')->first();
        if (! $website) throw new RuntimeException('The verified candidate website is unavailable.');
        $scan = DB::table('website_scans')->where('tenant_id', $this->tenantId)->where('company_website_id', $website->id)
            ->where('crawler_version', 'discovery-prepromotion-v1')->orderByDesc('created_at')->first();
        if (! $scan) throw new RuntimeException('The candidate scan was not initialized.');

        if ($this->stage !== 'scoring') {
            if ($scan->status !== 'completed') {
                DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update(['lifecycle_status' => 'analyzing', 'updated_at' => now()]);
                $crawler->crawl($this->tenantId, $website->id, min(10, (int) config('discovery.max_pages_per_domain', 10)), 2, $scan->id, 0);
            }
            $this->runAgent($agents, $candidate, 'WebsiteIntelligenceAgent', ['website_scan_id' => $scan->id], 'website-intelligence');
            DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update(['lifecycle_status' => 'analyzed', 'updated_at' => now()]);
        }
        if ($this->stage === 'scoring' && ! in_array($candidate->lifecycle_status, ['analyzed', 'reviewable'], true)) {
            throw new RuntimeException('Candidate intelligence must be complete before lead scoring.');
        }
        $this->runAgent($agents, $candidate, 'LeadScoringAgent', ['company_id' => $company->id], 'lead-score');
        DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $candidate->id)->update(['lifecycle_status' => 'reviewable', 'analysis_status' => 'completed', 'updated_at' => now()]);
        $this->refreshRun($candidate->discovery_run_id);
    }

    private function runAgent(AgentOrchestrator $agents, object $candidate, string $agent, array $input, string $stage): void
    {
        $key = 'discovery-candidate:'.$candidate->id.':'.$stage;
        $id = (string) Str::uuid();
        DB::table('agent_runs')->insertOrIgnore(['id' => $id, 'tenant_id' => $this->tenantId, 'agent_key' => $agent, 'status' => 'queued',
            'requested_by' => $this->actorId, 'input_hash' => hash('sha256', json_encode($input)), 'idempotency_key' => $key,
            'correlation_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('agent_runs')->where('tenant_id', $this->tenantId)->where('idempotency_key', $key)->first();
        if (! $run) throw new RuntimeException('Unable to create the candidate analysis run.');
        if ($run->status !== 'succeeded') $agents->run($agent, $this->tenantId, $input, $this->actorId, $run->id);
    }

    private function refreshRun(string $runId): void
    {
        $pending = DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('discovery_run_id', $runId)
            ->whereNotIn('deduplication_state', ['existing_company', 'duplicate_candidate'])
            ->where(function ($query): void { $query->where('verification_state', 'pending')->orWhereIn('lifecycle_status', ['verified', 'analyzing', 'analyzed']); })->exists();
        DB::table('discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $runId)->update([
            'status' => $pending ? 'analyzing' : 'completed', 'completed_at' => $pending ? null : now(),
            'counts' => json_encode($this->counts($runId)), 'updated_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('id', $this->candidateId)
            ->whereIn('lifecycle_status', ['verified', 'analyzing', 'analyzed'])->update([
                'lifecycle_status' => 'analysis_failed', 'failure_code' => 'CANDIDATE_ANALYSIS_FAILED',
                'analysis_status' => 'failed', 'failure_summary' => 'Website analysis or lead scoring did not complete. Retry the analysis or review the evidence.', 'updated_at' => now(),
            ]);
    }

    private function counts(string $runId): array
    {
        return (array) DB::table('discovery_candidates')->where('tenant_id', $this->tenantId)->where('discovery_run_id', $runId)
            ->selectRaw('count(*) as found, sum(case when deduplication_state in (\'existing_company\', \'duplicate_candidate\') then 1 else 0 end) as duplicates, sum(case when verification_state = \'invalid\' then 1 else 0 end) as invalid, sum(case when verification_state = \'not_required\' then 1 else 0 end) as no_website, sum(case when eligible_for_analysis then 1 else 0 end) as eligible_for_analysis, sum(case when verification_state = \'verified\' then 1 else 0 end) as verified, sum(case when lifecycle_status in (\'analyzed\', \'reviewable\', \'accepted\') then 1 else 0 end) as analyzed, sum(case when lifecycle_status in (\'reviewable\', \'accepted\') then 1 else 0 end) as scored, sum(case when lifecycle_status = \'accepted\' then 1 else 0 end) as accepted, sum(case when lifecycle_status = \'rejected\' then 1 else 0 end) as rejected, sum(case when verification_state in (\'failed\', \'unreachable\', \'robots_denied\') or lifecycle_status = \'analysis_failed\' then 1 else 0 end) as failures')->first();
    }
}
