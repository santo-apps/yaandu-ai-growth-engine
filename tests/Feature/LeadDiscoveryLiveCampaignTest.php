<?php

namespace Tests\Feature;

use App\Jobs\DiscoverLeadSourcePageJob;
use App\Jobs\StartLeadDiscoveryCampaignJob;
use App\Jobs\VerifyLeadDiscoveryCandidateJob;
use App\Crawling\PublicAddressResolverInterface;
use App\LeadDiscovery\LeadDiscoveryCandidateVerifier;
use App\LeadDiscovery\LeadDiscoveryQueryTaxonomy;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LeadDiscoverySourcePlanner;
use App\LeadDiscovery\LiveCandidateStager;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadDiscoveryLiveCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lead_discovery.enabled' => true, 'lead_discovery.live_enabled' => true, 'services.brave_search.key' => 'synthetic-secret',
            'lead_discovery.user_agent' => 'PulamTest/1.0 (ops@example.test)']);
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
        RateLimiter::clear('lead-discovery-provider:brave_search');
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['web' => ['results' => []]], 200)
            ->push(['error' => ['detail' => 'synthetic failure']], 503)
            ->push(['error' => ['detail' => 'synthetic failure']], 503)
            ->push(['error' => ['detail' => 'synthetic failure']], 503);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed', DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('run_status'));
        self::assertSame('running', DB::table('lead_discovery_runs')->where('id', $runs[1]['id'])->value('run_status'));
        self::assertSame('running', DB::table('lead_discovery_campaigns')->where('id', $campaign)->value('status'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $queueJob = new FakeJob;
            $queueJob->attempts = $attempt;
            (new DiscoverLeadSourcePageJob($tenant->id, $runs[1]['checkpoint']))->setJob($queueJob)
                ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
            if ($attempt < 3) self::assertTrue($queueJob->isReleased());
        }

        self::assertSame('completed_with_errors', DB::table('lead_discovery_runs')->where('id', $runs[1]['id'])->value('run_status'));
        self::assertSame('completed_with_errors', DB::table('lead_discovery_campaigns')->where('id', $campaign)->value('status'));
        self::assertSame(4, Http::recorded()->count());
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

    public function test_osm_local_pacing_reschedules_without_failure_or_budget_accounting(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-osm-pacing');
        Queue::fake();
        [$campaignId, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $this->configureOsmRun($tenant->id, $runs[0]);
        config(['lead_discovery.osm_min_request_interval_seconds' => 5]);
        RateLimiter::clear('lead-discovery-provider:osm_overpass');
        RateLimiter::hit('lead-discovery-provider:osm_overpass', 30);
        Http::fake();

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->first();
        self::assertSame('queued', $checkpoint->status);
        self::assertSame(0, (int) $checkpoint->attempts);
        self::assertNull($checkpoint->error_code);
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame('ready', DB::table('lead_discovery_sources')->where('id', $this->sourceForRun($runs[0]['id']))->value('health_state'));
        Queue::assertPushed(DiscoverLeadSourcePageJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_wikidata_local_pacing_is_not_counted_and_success_audit_omits_query_text(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-wikidata-pacing');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $this->configureWikidataRun($tenant->id, $runs[0]);
        config(['lead_discovery.wikidata_min_request_interval_seconds' => 5, 'lead_discovery.wikidata_user_agent' => 'Pulam tests <ops@example.test>']);
        $rateKey = 'lead-discovery-provider:wikidata';
        RateLimiter::clear($rateKey);
        RateLimiter::hit($rateKey, 30);
        Http::fake(['query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200)]);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->first();
        self::assertSame('queued', $checkpoint->status);
        self::assertSame(0, (int) $checkpoint->attempts);
        self::assertNull($checkpoint->error_code);
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame('ready', DB::table('lead_discovery_sources')->where('id', $this->sourceForRun($runs[0]['id']))->value('health_state'));
        Http::assertNothingSent();

        $this->travel(6)->seconds();
        RateLimiter::clear($rateKey);
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'), (string) DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('error_code'));
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'lead_discovery.source_page_completed')->first();
        $metadata = json_decode((string) $audit->metadata, true);
        self::assertSame('IN:industry-ecommerce_retail', $metadata['query_key']);
        self::assertSame('ecommerce_retail', $metadata['industry_key']);
        self::assertSame('IN', $metadata['country_code']);
        self::assertSame(0, $metadata['result_count']);
        self::assertSame(1, $metadata['logical_request_count']);
        self::assertSame(2, $metadata['stage_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metadata['query_sha256']);
        self::assertArrayNotHasKey('sparql', $metadata);
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
        RateLimiter::clear($rateKey);
    }

    public function test_unsupported_wikidata_industry_is_reported_while_other_campaign_sources_continue(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-wikidata-unsupported');
        Queue::fake();
        [$campaignId, $existingRuns] = $this->campaignWithSourceRuns($tenant->id, 1, 'queued');
        DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaignId)->update([
            'industry' => 'Marine Engineering', 'country_code' => 'SA', 'city' => 'Jeddah',
        ]);
        $wikidataId = $this->sourceForRun($existingRuns[0]['id']);
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $existingRuns[0]['checkpoint'])->delete();
        DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $existingRuns[0]['id'])->delete();
        DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->where('id', $wikidataId)->update([
            'source_key' => 'wikidata', 'health_state' => 'ready', 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'configuration' => json_encode(['license_or_authorization' => 'Synthetic CC0', 'storage_rights_confirmed' => true,
                'storage_rights_reference' => 'synthetic-cc0']),
        ]);
        $gleifId = (string) Str::uuid();
        DB::table('lead_discovery_sources')->insert([
            'id' => $gleifId, 'tenant_id' => $tenant->id, 'source_key' => 'gleif', 'provider_name' => 'Synthetic GLEIF',
            'source_type' => 'open_data', 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'allowed_collection_method' => 'official_public_api', 'configuration' => json_encode(['license_or_authorization' => 'CC0',
                'storage_rights_confirmed' => true, 'storage_rights_reference' => 'synthetic-gleif']),
            'enabled' => true, 'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new StartLeadDiscoveryCampaignJob($tenant->id, $campaignId, [$wikidataId, $gleifId]))
            ->handle(app(LeadDiscoveryQueryTaxonomy::class), app(LeadSourceAdapterRegistry::class), app(LeadDiscoverySourcePlanner::class));

        self::assertSame('SOURCE_QUERY_UNSUPPORTED', DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->where('id', $wikidataId)->value('last_error_code'));
        self::assertSame('not_configured', DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->where('id', $wikidataId)->value('health_state'));
        self::assertSame('running', DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaignId)->value('status'));
        self::assertSame(1, DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('campaign_id', $campaignId)->where('source_id', $gleifId)->count());
        self::assertSame(0, DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('campaign_id', $campaignId)->where('source_id', $wikidataId)->count());
        Queue::assertPushed(DiscoverLeadSourcePageJob::class, 1);
        Http::assertNothingSent();
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
    }

    public function test_osm_sibling_checkpoints_run_serially_and_count_one_request_each(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-osm-siblings');
        Queue::fake();
        [$campaignId, $runs] = $this->campaignWithSourceRuns($tenant->id, 2);
        foreach ($runs as $run) $this->configureOsmRun($tenant->id, $run);
        config(['lead_discovery.osm_min_request_interval_seconds' => 5]);
        RateLimiter::clear('lead-discovery-provider:osm_overpass');
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []], 200)]);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('completed', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[1]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('queued', DB::table('lead_discovery_checkpoints')->where('id', $runs[1]['checkpoint'])->value('status'));
        self::assertSame(0, (int) DB::table('lead_discovery_checkpoints')->where('id', $runs[1]['checkpoint'])->value('attempts'));
        self::assertSame(1, Http::recorded()->count(), 'A sibling request waits instead of running concurrently/too soon.');

        $this->travel(6)->seconds();
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[1]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        self::assertSame('completed', DB::table('lead_discovery_checkpoints')->where('id', $runs[1]['checkpoint'])->value('status'));
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('id', $runs[1]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->whereIn('id', array_column($runs, 'id'))->sum('source_errors'));
        self::assertSame(2, Http::recorded()->count());
        self::assertSame(0, DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaignId)->value('auto_promotion_enabled'));
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'lead_discovery.source_page_completed')->orderByDesc('created_at')->first();
        self::assertSame('FOSSGIS main', json_decode((string) $audit->metadata, true)['endpoint_name']);
        self::assertSame('https://overpass-api.de/api/interpreter', json_decode((string) $audit->metadata, true)['endpoint_url']);
    }

    public function test_osm_availability_failure_persists_checkpoint_code_and_degrades_source_health(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-osm-failure');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $this->configureOsmRun($tenant->id, $runs[0]);
        RateLimiter::clear('lead-discovery-provider:osm_overpass');
        Http::fake([
            'overpass-api.de/*' => Http::response(['temporary' => true], 502),
            'overpass.private.coffee/*' => Http::response(['temporary' => true], 503),
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) $this->travel(6)->seconds(); // Respect the OSM provider's configured per-request pacing.
            $queueJob = new FakeJob;
            $queueJob->attempts = $attempt;
            (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($queueJob)
                ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
            if ($attempt < 3) self::assertTrue($queueJob->isReleased());
        }

        $sourceId = $this->sourceForRun($runs[0]['id']);
        self::assertSame('failed', DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame('SOURCE_TEMPORARILY_UNAVAILABLE', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('error_code'));
        self::assertSame('degraded', DB::table('lead_discovery_sources')->where('tenant_id', $tenant->id)->where('id', $sourceId)->value('health_state'));
        self::assertSame('SOURCE_TEMPORARILY_UNAVAILABLE', DB::table('lead_discovery_sources')->where('id', $sourceId)->value('last_error_code'));
        self::assertSame(3, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'), 'Each actual provider retry is budgeted.');
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
        self::assertSame(6, Http::recorded()->count());
    }

    public function test_cursor_page_transient_failure_retries_only_that_page_and_preserves_prior_candidates(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-page-retry');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $results = [];
        for ($index = 1; $index <= 10; $index++) {
            $results[] = ['title' => 'Synthetic Retail Business '.$index, 'url' => 'https://retailer-'.$index.'.example.com/', 'description' => 'Synthetic result'];
        }
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['web' => ['results' => $results]], 200)
            ->push(['error' => ['detail' => 'synthetic unavailable']], 503)
            ->push(['web' => ['results' => [
                ['title' => 'Synthetic Retail Business 11', 'url' => 'https://retailer-11.example.com/'],
                ['title' => 'Synthetic Retail Business 12', 'url' => 'https://retailer-12.example.com/'],
            ]]], 200);

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->first();
        self::assertSame('queued', $checkpoint->status);
        self::assertSame('1', $checkpoint->cursor);
        self::assertSame(10, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('run_id', $runs[0]['id'])->count());

        $firstRetry = (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob(new FakeJob);
        $firstRetry->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertTrue($firstRetry->job->isReleased());
        self::assertSame(5, $firstRetry->job->releaseDelay);
        self::assertSame(10, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('run_id', $runs[0]['id'])->count());

        $secondRetryQueueJob = new FakeJob;
        $secondRetryQueueJob->attempts = 2;
        $secondRetry = (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($secondRetryQueueJob);
        $secondRetry->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->first();
        self::assertSame('completed', $checkpoint->status);
        self::assertSame(2, (int) $checkpoint->pages_completed);
        self::assertSame(3, (int) $checkpoint->attempts);
        self::assertSame(12, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('run_id', $runs[0]['id'])->count());
        self::assertSame(3, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(3, Http::recorded()->count());
        Http::assertSent(fn ($request) => $request['offset'] === 0);
        self::assertSame(2, Http::recorded(fn ($request) => $request['offset'] === 1)->count(), 'Only the failing cursor page is repeated.');
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
    }

    public function test_transient_provider_failure_is_bounded_to_three_attempts_and_permanent_auth_failure_is_not_retried(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-retry-bound');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        RateLimiter::clear('lead-discovery-provider:brave_search');
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['error' => ['detail' => 'busy']], 503)
            ->push(['error' => ['detail' => 'busy']], 503)
            ->push(['error' => ['detail' => 'busy']], 503);

        $releaseDelays = [];
        foreach ([1, 2, 3] as $queueAttempt) {
            $queueJob = new FakeJob;
            $queueJob->attempts = $queueAttempt;
            (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($queueJob)
                ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
            if ($queueAttempt < 3) {
                self::assertTrue($queueJob->isReleased());
                $releaseDelays[] = $queueJob->releaseDelay;
            }
        }
        self::assertSame([5, 15], $releaseDelays);

        self::assertSame('failed', DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame('SOURCE_TEMPORARILY_UNAVAILABLE', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('error_code'));
        self::assertSame(3, (int) DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('attempts'));
        self::assertSame(3, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(3, Http::recorded()->count());

    }

    public function test_permanent_source_auth_failure_is_not_retried(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-no-auth-retry');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        RateLimiter::clear('lead-discovery-provider:brave_search');
        Http::fake(['api.search.brave.com/*' => Http::response(['error' => ['detail' => 'unauthorized']], 401)]);
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('failed', DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame('SOURCE_AUTH_FAILED', DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->value('error_code'));
        self::assertSame(1, (int) DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('attempts'));
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_rate_limited_retry_respects_retry_after_without_changing_cursor_or_candidates(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-retry-after');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        RateLimiter::clear('lead-discovery-provider:brave_search');
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['error' => ['detail' => 'limited']], 429, ['Retry-After' => '90'])
            ->push(['error' => ['detail' => 'still limited']], 429, ['Retry-After' => gmdate(DATE_RFC7231, time() + 90)])
            ->push(['web' => ['results' => []]], 200);

        $first = (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob(new FakeJob);
        $first->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertTrue($first->job->isReleased());
        self::assertSame(90, $first->job->releaseDelay);
        self::assertSame('running', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame(0, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('run_id', $runs[0]['id'])->count());

        $secondQueueJob = new FakeJob;
        $secondQueueJob->attempts = 2;
        $secondRetry = (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($secondQueueJob);
        $secondRetry
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertTrue($secondQueueJob->isReleased());
        self::assertGreaterThanOrEqual(60, $secondQueueJob->releaseDelay, 'HTTP-date Retry-After values must be honored.');

        $thirdQueueJob = new FakeJob;
        $thirdQueueJob->attempts = 3;
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($thirdQueueJob)
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('completed', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame(3, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(3, Http::recorded()->count());
    }

    public function test_application_local_rate_limit_reschedules_without_provider_attempt_or_error_accounting(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-local-rate-limit');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $rateKey = 'lead-discovery-provider:brave_search';
        RateLimiter::clear($rateKey);
        config(['lead_discovery.live_rate_per_minute' => 1]);
        RateLimiter::hit($rateKey, 60);
        Http::preventStrayRequests();

        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));

        $checkpoint = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->first();
        self::assertSame('queued', $checkpoint->status);
        self::assertSame(0, (int) $checkpoint->attempts);
        self::assertNull($checkpoint->error_code);
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        Queue::assertPushed(DiscoverLeadSourcePageJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_local_pacing_after_a_transient_failure_requeues_running_checkpoint_for_a_fresh_job(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-retry-local-pacing');
        Queue::fake();
        [, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        $rateKey = 'lead-discovery-provider:brave_search';
        RateLimiter::clear($rateKey);
        config(['lead_discovery.live_rate_per_minute' => 1]);
        Http::fakeSequence('api.search.brave.com/*')
            ->push(['error' => ['detail' => 'busy']], 503)
            ->push(['web' => ['results' => []]], 200);

        $firstQueueJob = new FakeJob;
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($firstQueueJob)
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertTrue($firstQueueJob->isReleased());
        self::assertSame('running', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));

        $retryQueueJob = new FakeJob;
        $retryQueueJob->attempts = 2;
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($retryQueueJob)
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('queued', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame(1, (int) DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('attempts'));
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));

        $this->travel(61)->seconds();
        $freshQueueJob = new FakeJob;
        (new DiscoverLeadSourcePageJob($tenant->id, $runs[0]['checkpoint']))->setJob($freshQueueJob)
            ->handle(app(LeadSourceAdapterRegistry::class), app(LiveCandidateStager::class));
        self::assertSame('completed', DB::table('lead_discovery_checkpoints')->where('id', $runs[0]['checkpoint'])->value('status'));
        self::assertSame(2, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_requests'));
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('id', $runs[0]['id'])->value('source_errors'));
        self::assertSame(2, Http::recorded()->count());
    }

    public function test_candidate_robots_denial_is_reviewable_not_a_processing_error_and_does_not_trigger_sales_actions(): void
    {
        [$tenant] = $this->tenantAndUser('live-lead-discovery-candidate-robots');
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        Queue::fake();
        [$campaignId, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->update(['status' => 'completed']);
        $candidateId = (string) Str::uuid();
        DB::table('lead_discovery_candidates')->insert([
            'id' => $candidateId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaignId, 'run_id' => $runs[0]['id'],
            'source_id' => $this->sourceForRun($runs[0]['id']), 'company_name' => 'Synthetic Furniture Company',
            'normalized_name' => 'synthetic furniture company', 'claimed_website' => 'https://unverifiable.example/',
            'normalized_domain' => 'unverifiable.example', 'industry' => 'Retail', 'country_code' => 'IN',
            'processing_status' => 'discovered', 'validation_status' => 'pending', 'promotion_status' => 'not_promoted',
            'eligibility_status' => 'needs_review', 'discovered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['https://unverifiable.example/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200)]);
        \Illuminate\Support\Facades\Log::spy();

        (new VerifyLeadDiscoveryCandidateJob($tenant->id, $candidateId))->handle(app(LeadDiscoveryCandidateVerifier::class));

        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidateId)->first();
        self::assertSame('needs_review', $candidate->processing_status);
        self::assertSame('needs_review', $candidate->validation_status);
        self::assertSame('ROBOTS_DENIED', $candidate->validation_reason_code);
        self::assertSame('Website crawl permission could not be verified.', $candidate->validation_reason);
        self::assertSame(0, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('processing_errors'));
        self::assertSame('completed', DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('run_status'));
        self::assertSame('completed', DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant->id)->where('id', $campaignId)->value('status'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) =>
            $context['operation'] === 'lead_discovery_candidate_unverifiable'
            && $context['candidate_id'] === $candidateId
            && $context['safe_domain'] === 'unverifiable.example'
            && $context['failure_code'] === 'ROBOTS_DENIED'
        )->once();
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
    }

    public function test_unexpected_candidate_persistence_failure_is_counted_as_processing_error(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') self::markTestSkipped('The synthetic failure trigger uses SQLite test schema syntax.');
        [$tenant] = $this->tenantAndUser('live-lead-discovery-internal-error');
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        Queue::fake();
        [$campaignId, $runs] = $this->campaignWithSourceRuns($tenant->id, 1);
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant->id)->where('id', $runs[0]['checkpoint'])->update(['status' => 'completed']);
        $candidateId = (string) Str::uuid();
        DB::table('lead_discovery_candidates')->insert([
            'id' => $candidateId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaignId, 'run_id' => $runs[0]['id'],
            'source_id' => $this->sourceForRun($runs[0]['id']), 'company_name' => 'Synthetic Furniture Company',
            'normalized_name' => 'synthetic furniture company', 'claimed_website' => 'https://internal-error.example/',
            'normalized_domain' => 'internal-error.example', 'industry' => 'Retail', 'country_code' => 'IN',
            'processing_status' => 'discovered', 'validation_status' => 'pending', 'promotion_status' => 'not_promoted',
            'eligibility_status' => 'needs_review', 'discovered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("CREATE TRIGGER synthetic_candidate_evidence_failure BEFORE INSERT ON lead_discovery_candidate_evidence BEGIN SELECT RAISE(ABORT, 'synthetic persistence failure'); END");
        Http::fake([
            'https://internal-error.example/robots.txt' => Http::response('', 404),
            'https://internal-error.example/' => Http::response('<html><head><title>Synthetic Furniture Company</title></head><body></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        \Illuminate\Support\Facades\Log::spy();

        (new VerifyLeadDiscoveryCandidateJob($tenant->id, $candidateId))->handle(app(LeadDiscoveryCandidateVerifier::class));

        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidateId)->first();
        self::assertSame('failed', $candidate->processing_status);
        self::assertSame('VERIFICATION_FAILED', $candidate->validation_reason_code);
        self::assertSame(1, (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('processing_errors'));
        self::assertSame('completed_with_errors', DB::table('lead_discovery_runs')->where('tenant_id', $tenant->id)->where('id', $runs[0]['id'])->value('run_status'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) =>
            $context['operation'] === 'lead_discovery_processing_error'
            && $context['candidate_id'] === $candidateId
            && $context['failure_code'] === 'VERIFICATION_FAILED'
        )->once();
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        Queue::assertNotPushed(\App\Jobs\SendOutboundMessage::class);
    }

    private function tenantAndUser(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => 'Synthetic Discovery Owner', 'email' => $slug.'@example.test', 'password' => bcrypt('password')]);
        return [$tenant, $user];
    }

    private function configureOsmRun(string $tenantId, array $run): void
    {
        $sourceId = $this->sourceForRun($run['id']);
        DB::table('lead_discovery_sources')->where('tenant_id', $tenantId)->where('id', $sourceId)->update([
            'source_key' => 'osm_overpass', 'provider_name' => 'Synthetic Overpass',
            'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'configuration' => json_encode(['license_or_authorization' => 'Synthetic ODbL review', 'storage_rights_confirmed' => true,
                'storage_rights_reference' => 'synthetic-osm-terms']),
        ]);
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenantId)->where('id', $run['checkpoint'])
            ->update(['industry_key' => 'ecommerce_retail', 'bbox' => json_encode([25.0, 55.0, 25.2, 55.4])]);
    }

    private function configureWikidataRun(string $tenantId, array $run): void
    {
        $sourceId = $this->sourceForRun($run['id']);
        DB::table('lead_discovery_sources')->where('tenant_id', $tenantId)->where('id', $sourceId)->update([
            'source_key' => 'wikidata', 'provider_name' => 'Synthetic Wikidata',
            'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'configuration' => json_encode(['license_or_authorization' => 'Synthetic CC0 basis', 'storage_rights_confirmed' => true,
                'storage_rights_reference' => 'synthetic-cc0-reference']),
        ]);
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenantId)->where('id', $run['checkpoint'])
            ->update(['industry_key' => 'ecommerce_retail', 'country_code' => 'IN', 'query_key' => 'IN:industry-ecommerce_retail']);
    }

    private function sourceForRun(string $runId): string
    {
        return (string) DB::table('lead_discovery_runs')->where('id', $runId)->value('source_id');
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
