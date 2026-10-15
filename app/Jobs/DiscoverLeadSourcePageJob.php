<?php

namespace App\Jobs;

use App\LeadDiscovery\DiscoveryQuery;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LiveCandidateStager;
use App\LeadDiscovery\LiveLeadSourceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class DiscoverLeadSourcePageJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15];
    public int $timeout = 45;
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId, public readonly string $checkpointId)
    {
        $this->onQueue((string) config('lead_discovery.live_queue', 'lead-discovery'));
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->checkpointId; }

    public function handle(LeadSourceAdapterRegistry $registry, LiveCandidateStager $stager): void
    {
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $this->checkpointId)->first();
        if (! $checkpoint || in_array($checkpoint->status, ['completed', 'failed', 'stopped'], true)) return;
        // A second queued copy must not process the page concurrently. Queue
        // retries (attempt > 1) may reclaim a page left running by an escaped
        // infrastructure exception; provider failures are handled explicitly.
        if ($checkpoint->status === 'running' && $this->attempts() <= 1) return;
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->campaign_id)->first();
        $run = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->run_id)->first();
        $source = DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->source_id)->first();
        if (! $campaign || ! $run || ! $source) return;
        if ($campaign->status === 'paused' || $run->run_status === 'paused') return;
        if ($campaign->status === 'cancelled' || $run->run_status === 'cancelled') { $this->checkpointStatus($checkpoint, 'stopped', 'CAMPAIGN_CANCELLED'); $this->finishIfDone($campaign, $run); return; }
        $configuration = json_decode((string) $source->configuration, true) ?: [];
        $executionConfiguration = [...$configuration, 'source_enabled' => (bool) $source->enabled,
            'authorization_status' => $source->authorization_status, 'storage_rights_status' => $source->storage_rights_status];
        try {
            $adapter = $registry->get((string) $source->source_key);
            if (! $adapter instanceof \App\LeadDiscovery\SearchLeadSourceAdapterInterface) throw new LiveLeadSourceException('SOURCE_DISABLED', 'The configured source cannot perform live search.');
            $adapter->validateConfiguration($executionConfiguration);
        } catch (LiveLeadSourceException $error) {
            $this->fail($checkpoint, $run, $source, $error->errorCode); return;
        }
        if (now()->diffInSeconds($run->started_at) >= (int) config('lead_discovery.live_max_runtime_seconds')) {
            $this->stopRun($campaign, $run, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return;
        }
        $limits = $this->limits($campaign);
        if ((int) $run->source_requests >= $limits['requests'] || $this->campaignRequestCount($campaign) >= $limits['campaign_requests']
            || (int) $run->raw_candidates >= $limits['raw']
            || (int) $run->eligible_candidates >= (int) $campaign->target_leads || (int) $run->source_errors >= (int) config('lead_discovery.live_max_failures')) {
            $this->stopRun($campaign, $run, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return;
        }

        $isOsm = (string) $source->source_key === 'osm_overpass';
        $rateKey = 'lead-discovery-provider:'.(string) $source->source_key;
        $capability = $registry->get((string) $source->source_key)->capabilities();
        if ($isOsm && RateLimiter::tooManyAttempts($rateKey, 1)) {
            // Local pacing is not a provider failure. Reschedule a fresh queued
            // execution so it does not consume the current job's retry budget.
            self::dispatch($this->tenantId, $this->checkpointId)
                ->delay(now()->addSeconds(max(1, RateLimiter::availableIn($rateKey))));
            return;
        }
        if (! $isOsm && RateLimiter::tooManyAttempts($rateKey, max(1, min((int) config('lead_discovery.live_rate_per_minute'), $capability->defaultRateLimit ?: (int) config('lead_discovery.live_rate_per_minute'))))) {
            DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->increment('attempts');
            $freshCheckpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->first();
            if ((int) $freshCheckpoint->attempts >= 3) { $this->fail($checkpoint, $run, $source, 'SOURCE_RATE_LIMITED'); return; }
            $this->release(3); return;
        }
        $claimed = DB::transaction(function () use ($checkpoint, $campaign, $run, $limits): string {
            DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->lockForUpdate()->first();
            $fresh = DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->lockForUpdate()->first();
            if (! $fresh || in_array($fresh->status, ['completed', 'failed', 'stopped'], true)) return 'skip';
            if ($fresh->status === 'running' && $this->attempts() <= 1) return 'skip';
            if (! in_array($fresh->status, ['queued', 'running'], true)) return 'skip';
            $campaignRequests = (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('source_requests');
            if ($campaignRequests >= $limits['campaign_requests']) {
                DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $fresh->id)
                    ->update(['status' => 'stopped', 'error_code' => 'CAMPAIGN_PROVIDER_REQUEST_LIMIT', 'completed_at' => now(), 'updated_at' => now()]);
                return 'limit';
            }
            DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $fresh->id)->update([
                'status' => 'running', 'attempts' => DB::raw('attempts + 1'), 'last_requested_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->increment('source_requests');
            return 'claimed';
        });
        if ($claimed === 'limit') {
            $freshCampaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->first();
            $freshRun = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->first();
            $this->finishIfDone($freshCampaign, $freshRun);
            return;
        }
        if ($claimed !== 'claimed') return;
        RateLimiter::hit($rateKey, $isOsm ? max(1, (int) config('lead_discovery.osm_min_request_interval_seconds', 5)) : 60);
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
            'action' => 'lead_discovery.source_requested', 'subject_type' => 'lead_discovery', 'subject_id' => $checkpoint->id,
            'metadata' => json_encode(['campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key]), 'created_at' => now()]);

        try {
            $page = $adapter->search(new DiscoveryQuery(
                (string) $campaign->id, $this->tenantId, (string) ($campaign->industry ?? ''), (string) ($checkpoint->country_code ?? $campaign->country_code ?? ''),
                $campaign->region, $campaign->city, (string) $checkpoint->query_key, (string) $checkpoint->query_text,
                min((int) config('lead_discovery.live_result_count', 10), (int) $limits['raw']), $checkpoint->cursor,
                ['key' => $checkpoint->partition_key ?? '', 'country_code' => $checkpoint->country_code ?? null, 'region' => $checkpoint->region ?? null,
                    'city' => $checkpoint->city ?? null, 'bbox' => $checkpoint->bbox ? json_decode((string) $checkpoint->bbox, true) : null],
                $checkpoint->industry_key ?? null,
            ), $executionConfiguration);
            $freshCampaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->first();
            if (! $freshCampaign || $freshCampaign->status === 'cancelled') {
                $this->checkpointStatus($checkpoint, 'stopped', 'CAMPAIGN_CANCELLED');
                $this->finishIfDone($freshCampaign, $run);
                return;
            }
            $created = 0; $duplicates = 0;
            foreach ($page->candidates as $index => $candidate) {
                $currentCount = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->count();
                $currentProcessing = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                    ->whereNotIn('processing_status', ['invalid', 'duplicate'])->count();
                if ($currentCount >= $limits['candidates'] || $currentProcessing >= $limits['processing']) break;
                $result = DB::transaction(fn () => $stager->stage($this->tenantId, $campaign, $run, $source, $checkpoint,
                    [...$candidate, '_request_id' => $page->providerRequestId], $index + 1 + ((int) $checkpoint->pages_completed * (int) config('lead_discovery.live_result_count', 10))));
                if ($result['created']) {
                    $created++;
                    if ($result['verification_id']) VerifyLeadDiscoveryCandidateJob::dispatch($this->tenantId, (string) $result['verification_id'])->afterCommit();
                }
                if ($result['duplicate']) $duplicates++;
            }
            DB::transaction(function () use ($checkpoint, $run, $created, $duplicates, $page): void {
                DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->update([
                    'cursor' => $page->nextCursor, 'pages_completed' => DB::raw('pages_completed + 1'), 'raw_results' => DB::raw('raw_results + '.count($page->candidates)),
                    'status' => $page->exhausted ? 'completed' : 'queued', 'completed_at' => $page->exhausted ? now() : null,
                    'updated_at' => now(),
                ]);
                DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->update([
                    'raw_candidates' => DB::raw('raw_candidates + '.count($page->candidates)),
                    'candidates_found' => DB::raw('candidates_found + '.$created),
                    'duplicates_found' => DB::raw('duplicates_found + '.$duplicates), 'updated_at' => now(),
                ]);
            });
            DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->where('id', $source->id)->update([
                'health_state' => 'ready', 'last_success_at' => now(), 'last_error_code' => null, 'updated_at' => now(),
            ]);
            Log::info('Lead discovery source page staged.', ['operation' => 'lead_discovery_source_page_completed', 'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key,
                'request_count' => 1, 'candidate_count' => $created, 'duplicate_count' => $duplicates]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
                'action' => 'lead_discovery.source_page_completed', 'subject_type' => 'lead_discovery', 'subject_id' => $checkpoint->id,
                'metadata' => json_encode(['campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key,
                    'request_count' => 1, 'candidate_count' => $created, 'duplicate_count' => $duplicates,
                    'endpoint_name' => data_get($page->providerMetadata, 'endpoint_name'), 'endpoint_url' => data_get($page->providerMetadata, 'endpoint_url')]), 'created_at' => now()]);
            $freshRun = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->first();
            $campaignCandidateCount = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->count();
            if (! $page->exhausted && $freshCampaign->status !== 'paused' && $campaignCandidateCount < $limits['candidates'] && $this->withinLimits($campaign, $freshRun)) self::dispatch($this->tenantId, $this->checkpointId)->delay(now()->addSeconds(2));
            elseif (! $page->exhausted && $freshCampaign->status !== 'paused' && $campaignCandidateCount >= $limits['candidates']) { $this->stopRun($campaign, $freshRun, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return; }
            $this->finishIfDone($campaign, $freshRun);
        } catch (LiveLeadSourceException $error) {
            if ($error->errorCode === 'SOURCE_RATE_LIMITED' && (int) $checkpoint->attempts + 1 < 3) { $this->release(max(1, $error->retryAfter ?? 5)); return; }
            $this->fail($checkpoint, $run, $source, $error->errorCode);
        } catch (\Throwable $error) {
            $this->fail($checkpoint, $run, $source, 'SOURCE_RESPONSE_INVALID');
        }
    }

    private function limits(object $campaign): array
    {
        $config = json_decode((string) $campaign->configuration, true) ?: [];
        return [
            'requests' => min((int) config('lead_discovery.live_max_source_requests'), (int) data_get($config, 'limits.source_requests', config('lead_discovery.live_max_source_requests'))),
            'campaign_requests' => min((int) config('lead_discovery.live_max_provider_requests', 50), (int) data_get($config, 'limits.provider_requests', config('lead_discovery.live_max_provider_requests', 50)), (int) data_get($config, 'limits.source_requests', config('lead_discovery.live_max_source_requests'))),
            'raw' => min((int) config('lead_discovery.live_max_raw_candidates'), (int) data_get($config, 'limits.raw_candidates', config('lead_discovery.live_max_raw_candidates'))),
            'candidates' => min((int) config('lead_discovery.live_max_raw_candidates'), (int) data_get($config, 'limits.candidates', config('lead_discovery.max_candidates_per_run'))),
            'processing' => min((int) config('lead_discovery.live_max_raw_candidates'), (int) data_get($config, 'limits.processing', config('lead_discovery.max_candidate_processing'))),
        ];
    }

    private function withinLimits(object $campaign, object $run): bool
    {
        $limits = $this->limits($campaign);
        return (int) $run->source_requests < $limits['requests'] && $this->campaignRequestCount($campaign) < $limits['campaign_requests'] && (int) $run->raw_candidates < $limits['raw']
            && now()->diffInSeconds($run->started_at) < (int) config('lead_discovery.live_max_runtime_seconds');
    }

    private function campaignRequestCount(object $campaign): int
    {
        return (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('source_requests');
    }

    private function fail(object $checkpoint, object $run, object $source, string $code): void
    {
        DB::transaction(function () use ($checkpoint, $run, $source, $code): void {
            DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
            DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->increment('source_errors');
            DB::table('lead_discovery_sources')->where('tenant_id', $this->tenantId)->where('id', $source->id)->update(['health_state' => $code === 'SOURCE_AUTH_FAILED' ? 'auth_error' : 'degraded', 'last_error_code' => $code, 'updated_at' => now()]);
        });
        Log::warning('Lead discovery source failed safely.', ['operation' => 'lead_discovery_source_failed', 'tenant_id' => $this->tenantId,
            'campaign_id' => $checkpoint->campaign_id, 'run_id' => $checkpoint->run_id, 'source_id' => $checkpoint->source_id,
            'query_key' => $checkpoint->query_key, 'error_code' => $code]);
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
            'action' => 'lead_discovery.source_failed', 'subject_type' => 'lead_discovery', 'subject_id' => $checkpoint->id,
            'metadata' => json_encode(['campaign_id' => $checkpoint->campaign_id, 'run_id' => $checkpoint->run_id, 'source_id' => $checkpoint->source_id,
                'query_key' => $checkpoint->query_key, 'error_code' => $code]), 'created_at' => now()]);
        $this->finishIfDone(DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->campaign_id)->first(),
            DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->first());
    }

    private function stopRun(object $campaign, object $run, object $checkpoint, string $code): void
    {
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('run_id', $run->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'stopped', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->update(['last_error_code' => $code, 'updated_at' => now()]);
        $this->finishIfDone(DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->first(),
            DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->first());
    }

    private function checkpointStatus(object $checkpoint, string $status, ?string $code = null): void
    {
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->update(['status' => $status, 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function finishIfDone(?object $campaign, ?object $run): void
    {
        if (! $campaign || ! $run || in_array($campaign->status, ['paused', 'cancelled'], true)) return;
        if ($run->run_status === 'paused') return;
        $pending = DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('run_id', $run->id)->whereIn('status', ['queued', 'running'])->exists();
        if ($pending) return;
        if (DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('run_id', $run->id)->whereIn('processing_status', ['discovered', 'validating'])->exists()) return;
        $terminalStatuses = ['completed', 'completed_with_errors', 'failed', 'cancelled'];
        if (! in_array($run->run_status, $terminalStatuses, true)) {
            $failed = DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('run_id', $run->id)->where('status', 'failed')->exists();
            DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->update(['run_status' => $failed ? 'completed_with_errors' : 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
                'action' => 'lead_discovery.run_completed', 'subject_type' => 'lead_discovery', 'subject_id' => $run->id,
                'metadata' => json_encode(['campaign_id' => $campaign->id, 'source_requests' => $run->source_requests, 'raw_candidates' => $run->raw_candidates]), 'created_at' => now()]);
        }

        $runs = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id);
        if ($runs->whereNotIn('run_status', $terminalStatuses)->exists()) return;
        $hasErrors = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
            ->whereIn('run_status', ['completed_with_errors', 'failed', 'cancelled'])->exists();
        DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)
            ->whereNotIn('status', ['paused', 'cancelled'])->update([
                'status' => $hasErrors ? 'completed_with_errors' : 'completed', 'completed_at' => now(), 'updated_at' => now(),
            ]);
    }
}
