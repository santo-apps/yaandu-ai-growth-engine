<?php

namespace App\Jobs;

use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LiveLeadSourceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class StartLeadDiscoveryCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    /** @param list<string> $sourceIds */
    public function __construct(public readonly string $tenantId, public readonly string $campaignId, public readonly array $sourceIds)
    {
        $this->onQueue((string) config('lead_discovery.live_queue', 'lead-discovery'));
    }

    public function handle(LeadDiscoveryQueryTaxonomy $taxonomy, LeadSourceAdapterRegistry $registry): void
    {
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $this->campaignId)->first();
        if (! $campaign || $campaign->status !== 'queued') return;
        if (! config('lead_discovery.live_enabled')) { $this->failCampaign($campaign, 'SOURCE_DISABLED'); return; }
        $queries = $taxonomy->forCampaign($campaign);
        if ($queries === []) { $this->failCampaign($campaign, 'SOURCE_DISABLED'); return; }
        $sources = DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->whereIn('id', $this->sourceIds)
            ->where('source_key', 'brave_search')->get();
        if ($sources->count() !== count(array_unique($this->sourceIds))) { $this->failCampaign($campaign, 'SOURCE_NOT_AUTHORIZED'); return; }

        $jobs = [];
        foreach ($sources as $source) {
            $configuration = json_decode((string) $source->configuration, true) ?: [];
            try {
                $registry->get((string) $source->source_key)->validateConfiguration([...$configuration,
                    'source_enabled' => (bool) $source->enabled, 'authorization_status' => $source->authorization_status,
                    'storage_rights_status' => $source->storage_rights_status]);
            } catch (LiveLeadSourceException $error) {
                DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->where('id', $source->id)->update(['health_state' => 'not_configured', 'last_error_code' => $error->errorCode, 'updated_at' => now()]);
                $this->failCampaign($campaign, $error->errorCode);
                return;
            }
            $runId = (string) Str::uuid();
            DB::transaction(function () use ($source, $queries, $campaign, $runId, &$jobs): void {
                DB::table('lead_discovery_runs')->insert(['id' => $runId, 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                    'source_id' => $source->id, 'run_status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($queries as $query) {
                    $id = (string) Str::uuid();
                    DB::table('lead_discovery_checkpoints')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                        'run_id' => $runId, 'source_id' => $source->id, 'query_key' => $query['key'], 'query_text' => $query['query'],
                        'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                    $jobs[] = [$this->tenantId, $id];
                }
                DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
                    'action' => 'lead_discovery_run_started', 'subject_type' => 'lead_discovery', 'subject_id' => $runId,
                    'metadata' => json_encode(['run_id' => $runId, 'source_id' => $source->id, 'query_count' => count($queries), 'automatic_promotion' => false]), 'created_at' => now()]);
            });
        }
        if ($jobs === []) { $this->failCampaign($campaign, 'SOURCE_NOT_CONFIGURED'); return; }
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        foreach ($jobs as [$tenant, $checkpoint]) DiscoverLeadSourcePageJob::dispatch($tenant, $checkpoint);
        Log::info('Lead discovery campaign started.', ['operation' => 'lead_discovery_campaign_started', 'tenant_id' => $this->tenantId,
            'campaign_id' => $campaign->id, 'source_count' => count($this->sourceIds), 'query_count' => count($queries)]);
    }

    private function failCampaign(object $campaign, string $code): void
    {
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->update(['status' => 'failed', 'last_error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
        Log::warning('Lead discovery campaign could not start.', ['operation' => 'lead_discovery_campaign_start_failed', 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id, 'error_code' => $code]);
    }
}
