<?php

namespace App\Jobs;

use App\LeadDiscovery\DiscoveryQuery;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LiveCandidateStager;
use App\LeadDiscovery\LiveLeadSourceException;
use App\LeadDiscovery\LeadDiscoveryPilotSummary;
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
    public array $backoff = [5, 15, 30];
    public int $timeout = 45;
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId, public readonly string $checkpointId)
    {
        $this->onQueue((string) config('lead_discovery.live_queue', 'lead-discovery'));
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->checkpointId; }

    public function handle(LeadSourceAdapterRegistry $registry, LiveCandidateStager $stager, ?LeadDiscoveryPilotSummary $pilotSummary = null): void
    {
        $pilotSummary ??= app(LeadDiscoveryPilotSummary::class);
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
        if ($this->isPilot($campaign) && $pilotSummary->targetCount($this->tenantId, (string) $campaign->id) >= (int) $this->pilotTarget($campaign)) {
            $this->stopPilotCheckpoints($campaign, $pilotSummary);
            return;
        }
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
        $runtimeOrigin = $this->isPilot($campaign) && $campaign->started_at ? $campaign->started_at : $run->started_at;
        if (now()->diffInSeconds($runtimeOrigin) >= (int) config('lead_discovery.live_max_runtime_seconds')) {
            if ($this->isPilot($campaign)) {
                $this->stopPilotCheckpoints($campaign, $pilotSummary, 'PILOT_RUNTIME_LIMIT_REACHED');
                return;
            }
            $this->stopRun($campaign, $run, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return;
        }
        $limits = $this->limits($campaign);
        if ((int) $run->source_requests >= $limits['requests'] || $this->campaignRequestCount($campaign) >= $limits['campaign_requests']
            || (int) $run->raw_candidates >= $limits['raw']
            || (int) $run->eligible_candidates >= (int) $campaign->target_leads || (int) $run->source_errors >= (int) config('lead_discovery.live_max_failures')) {
            $this->stopRun($campaign, $run, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return;
        }

        $pacingInterval = match ((string) $source->source_key) {
            'osm_overpass' => max(1, (int) config('lead_discovery.osm_min_request_interval_seconds', 5)),
            'wikidata' => max(1, (int) config('lead_discovery.wikidata_min_request_interval_seconds', 5)),
            default => null,
        };
        $rateKey = 'lead-discovery-provider:'.(string) $source->source_key;
        $capability = $registry->get((string) $source->source_key)->capabilities();
        if ($pacingInterval !== null && RateLimiter::tooManyAttempts($rateKey, 1)) {
            // Local pacing is not a provider failure. Reschedule a fresh queued
            // execution so it does not consume the current job's retry budget.
            $this->queueCheckpointForLocalPacing($checkpoint);
            self::dispatch($this->tenantId, $this->checkpointId)
                ->delay(now()->addSeconds(max(1, RateLimiter::availableIn($rateKey))));
            return;
        }
        if ($pacingInterval === null && RateLimiter::tooManyAttempts($rateKey, max(1, min((int) config('lead_discovery.live_rate_per_minute'), $capability->defaultRateLimit ?: (int) config('lead_discovery.live_rate_per_minute'))))) {
            // Application-local pacing does not count as a provider attempt.
            $this->queueCheckpointForLocalPacing($checkpoint);
            self::dispatch($this->tenantId, $this->checkpointId)
                ->delay(now()->addSeconds(max(1, RateLimiter::availableIn($rateKey))));
            return;
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
        RateLimiter::hit($rateKey, $pacingInterval ?? 60);
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
            $created = 0; $duplicates = 0; $pilotTargetReached = false;
            foreach ($page->candidates as $index => $candidate) {
                $currentCount = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->count();
                $currentProcessing = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                    ->whereNotIn('processing_status', ['invalid', 'duplicate'])->count();
                if ($currentCount >= $limits['candidates'] || $currentProcessing >= $limits['processing']) break;
                $result = DB::transaction(function () use ($campaign, $pilotSummary, $stager, $run, $source, $checkpoint, $candidate, $page, $index): array {
                    $lockedCampaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)->lockForUpdate()->first();
                    if (! $lockedCampaign) return ['created' => false, 'duplicate' => false, 'verification_id' => null, 'target_reached' => true];
                    if ($this->isPilot($lockedCampaign) && $pilotSummary->targetCount($this->tenantId, (string) $lockedCampaign->id) >= (int) $this->pilotTarget($lockedCampaign)) {
                        return ['created' => false, 'duplicate' => false, 'verification_id' => null, 'target_reached' => true];
                    }
                    $staged = $stager->stage($this->tenantId, $lockedCampaign, $run, $source, $checkpoint,
                        [...$candidate, '_request_id' => $page->providerRequestId], $index + 1 + ((int) $checkpoint->pages_completed * (int) config('lead_discovery.live_result_count', 10)));
                    $staged['target_reached'] = $this->isPilot($lockedCampaign)
                        && $pilotSummary->targetCount($this->tenantId, (string) $lockedCampaign->id) >= (int) $this->pilotTarget($lockedCampaign);
                    return $staged;
                });
                if ($result['created']) {
                    $created++;
                    if ($result['verification_id']) VerifyLeadDiscoveryCandidateJob::dispatch($this->tenantId, (string) $result['verification_id'])->afterCommit();
                }
                if ($result['duplicate']) $duplicates++;
                if ($result['target_reached'] ?? false) { $pilotTargetReached = true; break; }
            }
            DB::transaction(function () use ($checkpoint, $run, $created, $duplicates, $page, $pilotTargetReached): void {
                if ($pilotTargetReached) {
                    DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('campaign_id', $checkpoint->campaign_id)
                        ->whereIn('status', ['queued', 'running'])->update(['status' => 'stopped', 'error_code' => 'PILOT_TARGET_REACHED', 'completed_at' => now(), 'updated_at' => now()]);
                }
                DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)->update([
                    'cursor' => $page->nextCursor, 'pages_completed' => DB::raw('pages_completed + 1'), 'raw_results' => DB::raw('raw_results + '.count($page->candidates)),
                    'status' => $pilotTargetReached ? 'stopped' : ($page->exhausted ? 'completed' : 'queued'),
                    'error_code' => $pilotTargetReached ? 'PILOT_TARGET_REACHED' : null,
                    'completed_at' => ($pilotTargetReached || $page->exhausted) ? now() : null,
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
            if ((string) $source->source_key === 'osm_overpass' && (string) $source->health_state === 'degraded') {
                Log::info('Lead discovery source recovered after a bounded campaign request.', ['operation' => 'lead_discovery_source_recovered',
                    'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id, 'source_id' => $source->id,
                    'reason_code' => (string) ($source->last_error_code ?? 'SOURCE_TEMPORARILY_UNAVAILABLE'), 'health_status' => 'ready']);
            }
            $providerAuditData = (string) $source->source_key === 'wikidata' ? array_intersect_key($page->providerMetadata, array_flip([
                'query_key', 'industry_key', 'country_code', 'result_count', 'logical_request_count', 'query_sha256', 'stage_count',
            ])) : [];
            Log::info('Lead discovery source page staged.', ['operation' => 'lead_discovery_source_page_completed', 'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key,
                'request_count' => 1, 'candidate_count' => $created, 'duplicate_count' => $duplicates, ...$providerAuditData]);
            if ($this->isPilot($campaign)) {
                $summary = $pilotSummary->build($this->tenantId, (string) $campaign->id);
                Log::info('Lead discovery pilot progress.', ['operation' => 'lead_discovery_pilot_progress', 'tenant_id' => $this->tenantId,
                    'campaign_id' => $campaign->id, 'target' => (int) $summary['target'], 'unique_staged' => (int) $summary['unique_staged'],
                    'duplicates' => (int) $summary['duplicates'], 'reviewable' => (int) $summary['eligible_reviewable'],
                    'rejected' => (int) $summary['rejected'], 'source_count' => count($summary['source_contribution']),
                    'source_errors' => (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('source_errors'),
                    'processing_errors' => (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('processing_errors')]);
            }
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => null,
                'action' => 'lead_discovery.source_page_completed', 'subject_type' => 'lead_discovery', 'subject_id' => $checkpoint->id,
                'metadata' => json_encode(['campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id, 'query_key' => $checkpoint->query_key,
                    'request_count' => 1, 'candidate_count' => $created, 'duplicate_count' => $duplicates,
                    'endpoint_name' => data_get($page->providerMetadata, 'endpoint_name'), 'endpoint_url' => data_get($page->providerMetadata, 'endpoint_url'),
                    ...$providerAuditData]), 'created_at' => now()]);
            $freshRun = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('id', $run->id)->first();
            $campaignCandidateCount = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->count();
            if ($pilotTargetReached) {
                $this->stopPilotCheckpoints($campaign, $pilotSummary);
                return;
            }
            if (! $page->exhausted && $freshCampaign->status !== 'paused' && $campaignCandidateCount < $limits['candidates'] && $this->withinLimits($campaign, $freshRun)) self::dispatch($this->tenantId, $this->checkpointId)->delay(now()->addSeconds(2));
            elseif (! $page->exhausted && $freshCampaign->status !== 'paused' && $campaignCandidateCount >= $limits['candidates']) { $this->stopRun($campaign, $freshRun, $checkpoint, 'CAMPAIGN_LIMIT_REACHED'); return; }
            $this->finishIfDone($campaign, $freshRun);
        } catch (LiveLeadSourceException $error) {
            if (in_array($error->errorCode, ['SOURCE_TIMEOUT', 'SOURCE_TEMPORARILY_UNAVAILABLE', 'SOURCE_RATE_LIMITED'], true)
                && ((int) $checkpoint->attempts - (int) $checkpoint->pages_completed + 1) < 3) {
                // `attempts` is cumulative over the cursor chain. Subtracting
                // successful pages gives the retry number for this page only.
                $failedAttempt = (int) $checkpoint->attempts - (int) $checkpoint->pages_completed + 1;
                $backoff = [5, 15, 30];
                $delay = $backoff[min($failedAttempt - 1, count($backoff) - 1)];
                if ($error->errorCode === 'SOURCE_RATE_LIMITED' && $error->retryAfter !== null) {
                    $delay = max($delay, min(3600, max(1, $error->retryAfter)));
                }
                $this->logProviderRetry($checkpoint, $error, $failedAttempt, $delay);
                $this->release($delay);
                return;
            }
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

    private function isPilot(object $campaign): bool
    {
        $configuration = is_array($campaign->configuration ?? null) ? $campaign->configuration : (json_decode((string) ($campaign->configuration ?? '{}'), true) ?: []);
        return (bool) data_get($configuration, 'pilot_mode', false);
    }

    private function pilotTarget(object $campaign): int
    {
        $configuration = is_array($campaign->configuration ?? null) ? $campaign->configuration : (json_decode((string) ($campaign->configuration ?? '{}'), true) ?: []);
        return min(LeadDiscoveryPilotSummary::TARGET, max(1, (int) data_get($configuration, 'pilot_target', LeadDiscoveryPilotSummary::TARGET)));
    }

    private function stopPilotCheckpoints(object $campaign, LeadDiscoveryPilotSummary $summary, string $reason = 'PILOT_TARGET_REACHED'): void
    {
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'running'])->update(['status' => 'stopped', 'error_code' => $reason, 'completed_at' => now(), 'updated_at' => now()]);
        $runs = DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->get();
        foreach ($runs as $run) $this->finishIfDone($campaign, $run);
    }

    private function queueCheckpointForLocalPacing(object $checkpoint): void
    {
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $this->tenantId)->where('id', $checkpoint->id)
            ->where('status', 'running')->update(['status' => 'queued', 'updated_at' => now()]);
    }

    private function logProviderRetry(object $checkpoint, LiveLeadSourceException $error, int $attempt, int $delay): void
    {
        $metadata = [
            'operation' => 'lead_discovery_provider_retry',
            'tenant_id' => $this->tenantId,
            'campaign_id' => $checkpoint->campaign_id,
            'run_id' => $checkpoint->run_id,
            'source_id' => $checkpoint->source_id,
            'checkpoint_id' => $checkpoint->id,
            'query_key' => $checkpoint->query_key,
            'error_code' => $error->errorCode,
            'attempt' => $attempt,
            'max_attempts' => 3,
            'retry_delay' => $delay,
            // A page number is safe to log; provider cursors may contain
            // arbitrary source-specific state and are intentionally omitted.
            'page_identifier' => 'page:'.((int) $checkpoint->pages_completed + 1),
        ];

        if ($error->errorCode === 'SOURCE_RATE_LIMITED') {
            $metadata['retry_after_honored'] = $error->retryAfter !== null && $delay >= $error->retryAfter;
        }

        Log::warning('Lead discovery provider attempt will be retried.', $metadata);

        // Retry audit events follow the same tenant-scoped convention as the
        // source-request, page-completed, and source-failed audit records.
        try {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'actor_user_id' => null,
                'action' => 'lead_discovery.provider_retry',
                'subject_type' => 'lead_discovery',
                'subject_id' => $checkpoint->id,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit persistence is secondary to safely scheduling the bounded
            // provider retry. Keep its failure diagnostic bounded and safe.
            Log::warning('Lead discovery provider retry audit could not be persisted.', [
                'operation' => 'lead_discovery_provider_retry_audit_failed',
                'tenant_id' => $this->tenantId,
                'checkpoint_id' => $checkpoint->id,
            ]);
        }
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
        if ((string) $source->source_key === 'osm_overpass' && in_array($code, ['SOURCE_TIMEOUT', 'SOURCE_TEMPORARILY_UNAVAILABLE', 'SOURCE_RATE_LIMITED'], true)) {
            Log::warning('Lead discovery source entered transient health cooldown.', ['operation' => 'lead_discovery_source_health_cooldown',
                'tenant_id' => $this->tenantId, 'campaign_id' => $checkpoint->campaign_id, 'source_id' => $source->id,
                'reason_code' => $code, 'cooldown_seconds' => (int) config('lead_discovery.osm_health_cooldown_seconds', 300), 'health_status' => 'degraded']);
        }
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
        $updated = DB::table('lead_discovery_campaigns')->where('tenant_id', $this->tenantId)->where('id', $campaign->id)
            ->whereNotIn('status', ['paused', 'cancelled'])->update([
                'status' => $hasErrors ? 'completed_with_errors' : 'completed', 'completed_at' => now(), 'updated_at' => now(),
            ]);
        if ($updated > 0 && $this->isPilot($campaign)) {
            $summary = app(LeadDiscoveryPilotSummary::class)->build($this->tenantId, (string) $campaign->id);
            Log::info('Lead discovery pilot completed.', ['operation' => 'lead_discovery_pilot_completed', 'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id, 'target' => (int) $summary['target'], 'unique_staged' => (int) $summary['unique_staged'],
                'duplicates' => (int) $summary['duplicates'], 'reviewable' => (int) $summary['eligible_reviewable'],
                'rejected' => (int) $summary['rejected'], 'source_count' => count($summary['source_contribution']),
                'source_errors' => (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('source_errors'),
                'processing_errors' => (int) DB::table('lead_discovery_runs')->where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)->sum('processing_errors')]);
        }
    }
}
