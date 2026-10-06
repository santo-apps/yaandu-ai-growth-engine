<?php

namespace App\Jobs;

use App\Agents\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAgentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public int $uniqueFor = 600;

    public function __construct(public string $tenantId, public string $agentName, public array $input, public ?string $actorId, public string $runId)
    {
        $this->onQueue(match ($agentName) {
            'DiscoveryAgent' => 'discovery', 'WebsiteIntelligenceAgent' => 'intelligence',
            'LeadScoringAgent' => 'scoring', default => 'default',
        });
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $run = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $this->runId)->where('tenant_id', $this->tenantId)->first();
        if (! $run || $run->status === 'succeeded' || $run->status === 'cancelled') return;

        $orchestrator->run($this->agentName, $this->tenantId, $this->input, $this->actorId, $this->runId);
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->runId; }

    public function failed(?\Throwable $exception): void
    {
        $run = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $this->runId)
            ->where('tenant_id', $this->tenantId)->whereIn('status', ['queued', 'running'])->first();
        if (! $run) return;

        \Illuminate\Support\Facades\DB::transaction(function () use ($exception): void {
            \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $this->runId)->where('tenant_id', $this->tenantId)
                ->update(['status' => 'failed', 'error_code' => 'AGENT_EXECUTION_FAILED', 'error_summary' => 'The agent could not complete this request.', 'finished_at' => now(), 'updated_at' => now()]);
            $sequence = (int) \Illuminate\Support\Facades\DB::table('agent_events')->where('tenant_id', $this->tenantId)->where('agent_run_id', $this->runId)->max('sequence') + 1;
            \Illuminate\Support\Facades\DB::table('agent_events')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $this->tenantId, 'agent_run_id' => $this->runId, 'sequence' => $sequence,
                'event_key' => 'failed', 'payload' => json_encode(['error_code' => 'AGENT_EXECUTION_FAILED', 'safe_message' => 'The agent could not complete this request.', 'correlation_id' => $run->correlation_id]), 'created_at' => now()]);
        });
    }
}
