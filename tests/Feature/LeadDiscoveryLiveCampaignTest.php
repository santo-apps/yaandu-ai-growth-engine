<?php

namespace Tests\Feature;

use App\Jobs\DiscoverLeadSourcePageJob;
use App\Jobs\StartLeadDiscoveryCampaignJob;
use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LeadDiscoverySourcePlanner;
use App\LeadDiscovery\LiveCandidateStager;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadDiscoveryLiveCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lead_discovery.enabled' => true, 'lead_discovery.live_enabled' => true, 'services.brave_search.key' => 'synthetic-secret']);
    }

    public function test_start_campaign_runs_mocked_search_paginates_deduplicates_and_stages_for_existing_safe_verification(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('live-lead-discovery');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner); Queue::fake();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $source = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'brave_search', 'provider_name' => 'Brave Search API', 'license_or_authorization' => 'Synthetic provider agreement review',
            'storage_rights_reference' => 'Synthetic order form reference', 'storage_rights_confirmed' => true,
            'allowed_collection_method' => 'documented_search_api', 'enabled' => true,
        ])->assertCreated()->assertJsonPath('source_key', 'brave_search')->json();
        self::assertSame('confirmed', $source['storage_rights_status']);
        self::assertSame('available', $source['credential_status']);
        self::assertSame(true, $source['execution_ready']);
        $campaign = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Synthetic India retail search', 'industry' => 'E-commerce & Retail', 'country_code' => 'IN',
            'target_leads' => 25, 'source_ids' => [$source['id']],
        ])->assertCreated()->assertJsonPath('target_leads', 25)->assertJsonPath('auto_promotion_enabled', false)->json();
        $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/start', ['source_ids' => [$source['id']]])
            ->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonPath('auto_promotion', false);
        Queue::assertPushed(StartLeadDiscoveryCampaignJob::class, 1);

        (new StartLeadDiscoveryCampaignJob($tenant->id, $campaign['id'], [$source['id']]))->handle(app(LeadDiscoveryQueryTaxonomy::class), app(LeadSourceAdapterRegistry::class), app(LeadDiscoverySourcePlanner::class));
        $run = DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign['id'])->first();
        self::assertNotNull($run);
        self::assertSame(2, DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('run_id', $run->id)->count());
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('run_id', $run->id)->orderBy('query_key')->first();
        $results = [];
        for ($index = 1; $index <= 10; $index++) $results[] = ['title' => 'Synthetic Retail Business '.$index, 'url' => 'https://synthetic-retailer-'.$index.'.example.com/', 'description' => 'Synthetic public search snippet '.$index];
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['web' => ['results' => $results]], 200)
            ->push(['web' => ['results' => array_slice($results, 0, 2)]], 200);

        $queuedPage = Queue::pushed(DiscoverLeadSourcePageJob::class, fn ($job) => $job->checkpointId === $checkpoint->id)->first();
        self::assertNotNull($queuedPage);
        (new UniqueLock(Cache::store()))->release($queuedPage); // Model the worker releasing ShouldBeUniqueUntilProcessing before handle().
        (new DiscoverLeadSourcePageJob($tenant->id, $checkpoint->id))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $checkpoint->id)->first();
        self::assertSame('1', $checkpoint->cursor, 'Checkpoint error: '.$checkpoint->error_code.'; source error: '.DB::table('lead_discovery_sources')->where('id', $source['id'])->value('last_error_code'));
        Queue::assertPushed(DiscoverLeadSourcePageJob::class, 3); // 2 bounded industry queries plus the scheduled next cursor page.
        self::assertSame(10, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign['id'])->count());
        self::assertSame(10, DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign['id'])->where('field_name', 'search_snippet')->count());
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.search.brave.com/res/v1/web/search'
            && $request['country'] === 'IN' && $request['count'] === 10 && $request['offset'] === 0);
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        self::assertSame('unverified', DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->first()->official_domain_status);
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'lead_discovery.candidate_discovered']);
        Queue::assertPushed(\App\Jobs\VerifyLeadDiscoveryCandidateJob::class, 10);
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);

        // Process the actual queued page-two job against an overlapping provider page.
        $pageTwoJob = Queue::pushed(DiscoverLeadSourcePageJob::class, fn ($job) => $job->checkpointId === $checkpoint->id)->last();
        self::assertNotNull($pageTwoJob);
        (new UniqueLock(Cache::store()))->release($pageTwoJob);
        $pageTwoJob->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $checkpoint->id)->first();
        self::assertSame('completed', $checkpoint->status);
        Http::assertSent(fn ($request) => $request['offset'] === 1);
        self::assertCount(2, Http::recorded());
        self::assertSame(10, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign['id'])->count());
        self::assertSame(2, DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $run->id)->value('duplicates_found'));
    }

    public function test_campaign_waits_for_all_source_runs_and_finishes_with_errors_when_one_fails(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-multi-source');
        Queue::fake();
        [$campaign, $runs] = $this->campaignWithSourceRuns($tenant->id, 2);
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['web' => ['results' => []]], 200)
            ->push(['error' => ['detail' => 'synthetic failure']], 503);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed', DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('run_status'));
        self::assertSame('running', DB::table('lead_discovery_runs')->where('id', $runs[1]['id'])->value('run_status'));
        self::assertSame('running', DB::table('lead_discovery_campaigns')->where('id', $campaign)->value('status'));

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[1]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed_with_errors', DB::table('lead_discovery_runs')->where('id', $runs[1]['id'])->value('run_status'));
        self::assertSame('completed_with_errors', DB::table('lead_discovery_campaigns')->where('id', $campaign)->value('status'));
        self::assertSame(2, Http::recorded()->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
    }

    public function test_single_source_campaign_completes_after_its_only_run_is_terminal(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-single-source');
        Queue::fake();
        [$campaign, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed', DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('run_status'));
        self::assertSame('completed', DB::table('lead_discovery_campaigns')->where('id', $campaign)->value('status'));
    }

    public function test_paused_and_cancelled_campaigns_are_not_finalized_or_resumed_by_page_jobs(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-paused-cancelled');
        Queue::fake();
        [$pausedCampaign, $pausedRuns] = $this->campaignWithSourceRuns($tenant->id, 1, 'paused');
        [$cancelledCampaign, $cancelledRuns] = $this->campaignWithSourceRuns($tenant->id, 1, 'cancelled');
        Http::fake();

        (new DiscoverLeadSourcePageJob($tenant->id, $pausedRuns[0]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        (new DiscoverLeadSourcePageJob($tenant->id, $cancelledRuns[0]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('paused', DB::table('lead_discovery_campaigns')->where('id', $pausedCampaign)->value('status'));
        self::assertSame('cancelled', DB::table('lead_discovery_campaigns')->where('id', $cancelledCampaign)->value('status'));
        self::assertSame('queued', DB::table('lead_discovery_checkpoints')->where('id', $pausedRuns[0]['checkpoint'])->value('status'));
        self::assertSame('stopped', DB::table('lead_discovery_checkpoints')->where('id', $cancelledRuns[0]['checkpoint'])->value('status'));
        self::assertSame('cancelled', DB::table('lead_discovery_runs')->where('id', $cancelledRuns[0]['id'])->value('run_status'));
        Http::assertNothingSent();
    }

    public function test_live_source_requires_explicit_storage_rights_and_stays_tenant_scoped(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('live-lead-discovery-owner');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        [$otherTenant] = $this->tenantAndUser('live-lead-discovery-other');
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'brave_search', 'provider_name' => 'Brave', 'license_or_authorization' => 'Plan reviewed',
            'allowed_collection_method' => 'documented_search_api', 'enabled' => true,
        ])->assertUnprocessable();
        $sourceId = (string) Str::uuid();
        DB::table('lead_discovery_sources')->insert(['id' => $sourceId, 'tenant_id' => $otherTenant->id, 'source_key' => 'brave_search', 'provider_name' => 'Other tenant',
            'source_type' => 'licensed_search_api', 'authorization_status' => 'authorized', 'storage_rights_status' => 'not_confirmed', 'allowed_collection_method' => 'api', 'configuration' => '{}', 'enabled' => true,
            'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeader('X-Tenant-ID', $tenant->id)->patchJson('/api/v1/lead-discovery/sources/'.$sourceId, ['enabled' => false])->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/lead-discovery/source-health')->assertOk()
            ->assertJsonPath('credential_detected', true)->assertJsonPath('storage_rights_status', 'not_confirmed')
            ->assertJsonPath('authorization_status', 'not_approved')->assertJsonPath('health_status', 'blocked')
            ->assertJsonPath('status', 'STORAGE_RIGHTS_NOT_CONFIRMED');
    }

    public function test_brave_credential_alone_cannot_enable_or_execute_source(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('live-lead-discovery-no-rights');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/sources', [
            'source_key' => 'brave_search', 'provider_name' => 'Brave Search API', 'license_or_authorization' => 'Plan is approved for search access',
            'allowed_collection_method' => 'documented_search_api', 'storage_rights_confirmed' => false, 'enabled' => true,
        ])->assertUnprocessable();
        self::assertSame(0, DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->count());
        Http::fake();
        Http::assertNothingSent();
    }

    public function test_start_discovery_rejects_authorized_source_without_storage_rights_server_side(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('live-lead-discovery-missing-retention');
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner); Queue::fake(); Http::fake();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $sourceId = (string) Str::uuid();
        DB::table('lead_discovery_sources')->insert([
            'id' => $sourceId, 'tenant_id' => $tenant->id, 'source_key' => 'brave_search', 'provider_name' => 'Brave Search API',
            'source_type' => 'licensed_search_api', 'authorization_status' => 'authorized', 'storage_rights_status' => 'not_confirmed',
            'allowed_collection_method' => 'documented_search_api', 'configuration' => json_encode([
                'license_or_authorization' => 'Synthetic approved search plan', 'storage_rights_confirmed' => false,
                'storage_rights_reference' => '',
            ]), 'enabled' => true, 'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders($headers)->patchJson('/api/v1/lead-discovery/sources/'.$sourceId, ['enabled' => true])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'STORAGE_RIGHTS_NOT_CONFIRMED');
        $campaign = $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns', [
            'name' => 'Synthetic gated campaign', 'industry' => 'Retail', 'country_code' => 'IN',
        ])->assertCreated()->json();

        $this->withHeaders($headers)->postJson('/api/v1/lead-discovery/campaigns/'.$campaign['id'].'/start', ['source_ids' => [$sourceId]])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'STORAGE_RIGHTS_NOT_CONFIRMED');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        self::assertSame('draft', DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaign['id'])->value('status'));
    }

    private function tenantAndUser(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => 'Synthetic Discovery Owner', 'email' => $slug.'@example.test', 'password' => bcrypt('password')]);
        return [$tenant, $user];
    }

    /** @return array{string, list<array{id:string,checkpoint:string}>} */
    private function campaignWithSourceRuns(string $tenantId, int $sourceCount, string $status = 'running'): array
    {
        $campaignId = (string) Str::uuid();
        DB::table('lead_discovery_campaigns')->insert([
            'id' => $campaignId, 'tenant_id' => $tenantId, 'name' => 'Synthetic campaign', 'industry' => 'Retail', 'country_code' => 'IN',
            'configuration' => json_encode(['limits' => ['source_requests' => 5, 'raw_candidates' => 20, 'candidates' => 20, 'processing' => 20]]),
            'target_leads' => 20, 'status' => $status, 'auto_promotion_enabled' => false, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $runs = [];
        for ($index = 0; $index < $sourceCount; $index++) {
            $sourceId = (string) Str::uuid();
            $runId = (string) Str::uuid();
            $checkpointId = (string) Str::uuid();
            DB::table('lead_discovery_sources')->insert([
                'id' => $sourceId, 'tenant_id' => $tenantId, 'source_key' => 'brave_search', 'provider_name' => 'Synthetic Brave',
                'source_type' => 'licensed_search_api', 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
                'allowed_collection_method' => 'documented_search_api', 'configuration' => json_encode([
                    'license_or_authorization' => 'Synthetic approved plan', 'storage_rights_confirmed' => true, 'storage_rights_reference' => 'Synthetic reference',
                ]), 'enabled' => true, 'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lead_discovery_runs')->insert([
                'id' => $runId, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'source_id' => $sourceId,
                'run_status' => $status === 'running' ? 'running' : $status, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lead_discovery_checkpoints')->insert([
                'id' => $checkpointId, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'run_id' => $runId, 'source_id' => $sourceId,
                'query_key' => 'retail-'.$index, 'query_text' => 'retail business', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $runs[] = ['id' => $runId, 'checkpoint' => $checkpointId];
        }

        return [$campaignId, $runs];
    }
}
