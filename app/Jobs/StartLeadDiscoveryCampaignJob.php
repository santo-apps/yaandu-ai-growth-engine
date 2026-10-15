<?php

namespace App\Jobs;

use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LiveLeadSourceException;
use App\LeadDiscovery\LeadDiscoverySourcePlanner;
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

    public function handle(LeadDiscoveryQueryTaxonomy $taxonomy, LeadSourceAdapterRegistry $registry, LeadDiscoverySourcePlanner $planner): void
    {
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $this->campaignId)->first();
        if (! $campaign || $campaign->status !== 'queued') return;
        if (! config('lead_discovery.live_enabled')) { $this->failCampaign($campaign, 'SOURCE_DISABLED'); return; }
        $sourceIds = $this->sourceIds;
        if ($sourceIds === []) {
            $sourceIds = DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)
                ->whereIn('source_key', ['osm_overpass', 'wikidata', 'india_ogd_mca', 'gleif'])->where('enabled', true)
                ->where('authorization_status', 'authorized')->where('storage_rights_status', 'confirmed')->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
        $campaignConfig = json_decode((string) $campaign->configuration, true) ?: [];
        $campaignConfig['discovery_source_ids'] = array_values(array_unique($sourceIds));
        $campaignConfig['source_mode'] = 'selected';
        $campaign->configuration = json_encode($campaignConfig);
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)
            ->update(['configuration' => json_encode($campaignConfig), 'updated_at' => now()]);
        $sources = DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->whereIn('id', $sourceIds)->get();
        if ($sourceIds !== [] && $sources->count() !== count(array_unique($sourceIds))) { $this->failCampaign($campaign, 'SOURCE_NOT_AUTHORIZED'); return; }

        $approvedSources = [];
        foreach ($sources as $source) {
            $configuration = json_decode((string) $source->configuration, true) ?: [];
            try {
                $adapter = $registry->get((string) $source->source_key);
                if (! $adapter instanceof \App\LeadDiscovery\SearchLeadSourceAdapterInterface || ! $adapter->capabilities()->supportsLiveQuery) continue;
                if ($adapter->capabilities()->governanceClass === 'CONDITIONAL' && ! in_array($source->id, $sourceIds, true)) continue;
                $adapter->validateConfiguration([...$configuration,
                    'source_enabled' => (bool) $source->enabled, 'authorization_status' => $source->authorization_status,
                    'storage_rights_status' => $source->storage_rights_status]);
            } catch (LiveLeadSourceException $error) {
                DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->where('id', $source->id)->update(['health_state' => 'not_configured', 'last_error_code' => $error->errorCode, 'updated_at' => now()]);
                continue;
            }
            $approvedSources[] = $source;
        }
        $tasks = $planner->plan($campaign, $approvedSources);
        if ($tasks === []) { $this->failCampaign($campaign, 'NO_COMPATIBLE_SOURCE_PARTITIONS'); return; }

        $runIds = []; $jobs = []; $checkpointCounts = [];
        DB::transaction(function () use ($campaign, $tasks, &$runIds, &$jobs, &$checkpointCounts): void {
            foreach ($tasks as $task) {
                $source = $task['source'];
                if (! isset($runIds[$source->id])) {
                    $runIds[$source->id] = (string) Str::uuid();
                    $checkpointCounts[$source->id] = 0;
                    DB::table('lead_discovery_runs')->insert(['id' => $runIds[$source->id], 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                        'source_id' => $source->id, 'run_status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $partition = $task['partition']; $query = $task['query'];
                $checkpointId = (string) Str::uuid();
                $queryKey = mb_substr((string) $partition['key'].':'.$query['key'], 0, 80);
                DB::table('lead_discovery_checkpoints')->insert(['id' => $checkpointId, 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                    'run_id' => $runIds[$source->id], 'source_id' => $source->id, 'query_key' => $queryKey, 'query_text' => $query['query'],
                    'partition_key' => $partition['key'], 'country_code' => $partition['country_code'] ?? null, 'region' => $partition['region'] ?? null,
                    'city' => $partition['city'] ?? null, 'bbox' => isset($partition['bbox']) ? json_encode($partition['bbox']) : null,
                    'industry_key' => $query['industry_key'] ?? null, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                $jobs[] = [$this->tenantId, $checkpointId]; $checkpointCounts[$source->id]++;
            }
            foreach ($runIds as $sourceId => $runId) DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
                'action' => 'lead_discovery_run_started', 'subject_type' => 'lead_discovery', 'subject_id' => $runId,
                'metadata' => json_encode(['run_id' => $runId, 'source_id' => $sourceId, 'checkpoint_count' => $checkpointCounts[$sourceId], 'automatic_promotion' => false]), 'created_at' => now()]);
        });
        if ($jobs === []) { $this->failCampaign($campaign, 'SOURCE_NOT_CONFIGURED'); return; }
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        foreach ($jobs as [$tenant, $checkpoint]) DiscoverLeadSourcePageJob::dispatch($tenant, $checkpoint);
        Log::info('Lead discovery campaign started.', ['operation' => 'lead_discovery_campaign_started', 'tenant_id' => $this->tenantId,
            'campaign_id' => $campaign->id, 'source_count' => count($approvedSources), 'query_count' => count($tasks), 'checkpoint_count' => count($jobs)]);
    }

    private function failCampaign(object $campaign, string $code): void
    {
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->update(['status' => 'failed', 'last_error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
        Log::warning('Lead discovery campaign could not start.', ['operation' => 'lead_discovery_campaign_start_failed', 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id, 'error_code' => $code]);
    }
}
