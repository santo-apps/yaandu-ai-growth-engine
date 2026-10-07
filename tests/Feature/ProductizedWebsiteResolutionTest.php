<?php

namespace Tests\Feature;

use App\Crawling\PublicAddressResolverInterface;
use App\Crawling\RobotsRules;
use App\Crawling\UrlPolicy;
use App\Discovery\DomainNormalizer;
use App\Jobs\AnalyzeAndScoreDiscoveryCandidateJob;
use App\Jobs\ResolveWebsiteCandidateJob;
use App\Jobs\VerifyDiscoveryCandidateJob;
use App\Models\Tenant;
use App\Models\User;
use App\WebsiteResolution\BusinessWebsiteIdentityMatcher;
use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceInterface;
use App\WebsiteResolution\DirectoryDomainClassifier;
use App\WebsiteResolution\WebsiteIdentityPageExtractor;
use App\WebsiteResolution\WebsiteResolutionSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductizedWebsiteResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['website_resolution.enabled' => true, 'website_resolution.auto_resolve_high_confidence' => false]);
        config(['ai.local_acceptance.enabled' => true, 'ai.tasks.website_reasoning.provider' => 'deterministic', 'ai.tasks.website_reasoning.model' => 'local-acceptance-v1']);
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34']; }
        });
    }

    public function test_human_confirmed_deterministic_candidate_reenters_existing_verification_and_analysis_pipeline(): void
    {
        [$tenant, $user, $candidate, $run] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user);
        Queue::fake();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response('<html><head><title>Noura Boutique Dubai</title><meta name="viewport" content="width=device-width"></head><body><script type="application/ld+json">{"@type":"Organization","name":"Noura Boutique","url":"https://noura-boutique.example","address":{"@type":"PostalAddress","addressLocality":"Dubai","addressCountry":"UAE"}}</script>Retail boutique in Dubai</body></html>', 200, ['Content-Type' => 'text/html']));
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => [[
            'url' => 'https://noura-boutique.example/', 'source' => 'deterministic', 'candidate_type' => 'business', 'source_reference' => 'fixture:noura',
            'evidence' => [['signal' => 'fixture_candidate', 'polarity' => 'neutral', 'points' => 0, 'summary' => 'Deterministic acceptance candidate.', 'details' => []]],
        ]]]]);

        $request = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-resolution-1'])
            ->assertAccepted()->json();
        $resolutionId = $request['resolution']['id'];
        (new ResolveWebsiteCandidateJob($tenant->id, $resolutionId))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));

        $resolution = DB::table('website_resolutions')->where('tenant_id', $tenant->id)->where('id', $resolutionId)->first();
        self::assertSame('AMBIGUOUS', $resolution->state, 'A medium-confidence single candidate still requires human confirmation.');
        self::assertSame(1, DB::table('website_resolution_candidates')->where('tenant_id', $tenant->id)->where('resolution_id', $resolutionId)->count());
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
        $this->assertDatabaseHas('website_resolution_evidence', ['tenant_id' => $tenant->id, 'resolution_id' => $resolutionId, 'signal' => 'name_match']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/website-resolutions/{$resolutionId}/review", [
            'action' => 'confirm', 'candidate_id' => DB::table('website_resolution_candidates')->where('resolution_id', $resolutionId)->value('id'),
        ])->assertOk()->assertJsonPath('resolution.state', 'RESOLVED');

        (new VerifyDiscoveryCandidateJob($tenant->id, $run, $candidate))->handle(app(UrlPolicy::class), app(DomainNormalizer::class), app(RobotsRules::class));
        $row = DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate)->first();
        self::assertSame('verified', $row->verification_state);
        self::assertSame('analyzing', $row->lifecycle_status);
        (new AnalyzeAndScoreDiscoveryCandidateJob($tenant->id, $candidate))->handle(app(\App\Crawling\CrawlerService::class), app(\App\Agents\AgentOrchestrator::class));
        self::assertSame('reviewable', DB::table('discovery_candidates')->where('id', $candidate)->value('lifecycle_status'));
        $this->assertDatabaseMissing('campaign_recipients', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('outbound_messages', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('workflow_approvals', ['tenant_id' => $tenant->id, 'action' => 'SEND_OUTREACH']);
    }

    public function test_two_plausible_candidates_remain_ambiguous_and_do_not_attach_a_domain(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404) : Http::response('<html><head><title>Noura Boutique Dubai</title></head><body><script type="application/ld+json">{"@type":"Organization","name":"Noura Boutique","address":{"addressLocality":"Dubai","addressCountry":"UAE"}}</script>Noura Boutique Dubai</body></html>', 200));
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => array_map(fn ($domain) => ['url' => 'https://'.$domain.'/', 'source' => 'deterministic', 'source_reference' => 'fixture:'.$domain, 'evidence' => []], ['noura-one.example', 'noura-two.example'])]]);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-ambiguous-1'])->assertAccepted()->json();
        $resolutionId = $response['resolution']['id'];
        (new ResolveWebsiteCandidateJob($tenant->id, $resolutionId))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));
        self::assertSame('AMBIGUOUS', DB::table('website_resolutions')->where('id', $resolutionId)->value('state'));
        self::assertSame(2, DB::table('website_resolution_candidates')->where('resolution_id', $resolutionId)->count());
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
    }

    public function test_directory_candidate_is_rejected_and_never_assigned_as_official_website(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake(); Http::fake();
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => [[
            'url' => 'https://www.yelp.com/biz/noura-boutique', 'source' => 'deterministic', 'source_reference' => 'fixture:yelp', 'evidence' => [],
        ]]]]);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-directory-1'])->assertAccepted()->json();
        (new ResolveWebsiteCandidateJob($tenant->id, $response['resolution']['id']))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));
        self::assertSame('rejected', DB::table('website_resolution_candidates')->value('status'));
        self::assertSame('UNRESOLVED', DB::table('website_resolutions')->value('state'));
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
        self::assertSame([], Http::recorded()->all(), 'Directory candidates must not be crawled.');
    }

    public function test_same_name_business_with_conflicting_country_is_rejected(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404) : Http::response('<html><head><script type="application/ld+json">{"@type":"Organization","name":"Noura Boutique","address":{"addressLocality":"London","addressCountry":"United Kingdom"}}</script></head><body>Noura Boutique London</body></html>', 200));
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => [[
            'url' => 'https://noura-london.example/', 'source' => 'deterministic', 'source_reference' => 'fixture:wrong-country', 'evidence' => [],
        ]]]]);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-wrong-country-1'])->assertAccepted()->json();
        (new ResolveWebsiteCandidateJob($tenant->id, $response['resolution']['id']))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));
        self::assertSame('rejected', DB::table('website_resolution_candidates')->where('resolution_id', $response['resolution']['id'])->value('status'));
        $this->assertDatabaseHas('website_resolution_evidence', ['resolution_id' => $response['resolution']['id'], 'signal' => 'country_contradiction', 'polarity' => 'negative']);
        self::assertSame('UNRESOLVED', DB::table('website_resolutions')->where('id', $response['resolution']['id'])->value('state'));
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
    }

    public function test_malicious_candidate_url_is_rejected_before_any_request(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake(); Http::fake();
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => [[
            'url' => 'file:///etc/passwd', 'source' => 'deterministic', 'source_reference' => 'fixture:unsafe', 'evidence' => [],
        ]]]]);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-unsafe-url-1'])->assertAccepted()->json();
        (new ResolveWebsiteCandidateJob($tenant->id, $response['resolution']['id']))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));
        self::assertSame('UNRESOLVED', DB::table('website_resolutions')->where('id', $response['resolution']['id'])->value('state'));
        $this->assertDatabaseHas('website_resolution_evidence', ['resolution_id' => $response['resolution']['id'], 'signal' => 'invalid_candidate_url']);
        self::assertCount(0, Http::recorded());
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
    }

    public function test_candidate_fetch_failure_is_failed_not_an_ambiguous_identity_match(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404) : Http::response('unavailable', 503));
        config(['website_resolution.deterministic_fixtures' => ['noura boutique' => [[
            'url' => 'https://noura-boutique.example/', 'source' => 'deterministic', 'source_reference' => 'fixture:offline', 'evidence' => [],
        ]]]]);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-offline-1'])->assertAccepted()->json();
        $job = new ResolveWebsiteCandidateJob($tenant->id, $response['resolution']['id']);
        $dependencies = [app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class)];
        $job->handle(...$dependencies);
        $resolution = DB::table('website_resolutions')->where('id', $response['resolution']['id'])->first();
        self::assertSame('FAILED', $resolution->state);
        self::assertSame('NETWORK_FAILURE', $resolution->failure_code);
        self::assertSame('failed', DB::table('website_resolution_candidates')->where('resolution_id', $resolution->id)->value('status'));
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
        $job->handle(...$dependencies);
        self::assertSame(8, DB::table('website_resolution_attempts')->where('resolution_id', $resolution->id)->count());
        self::assertSame(4, DB::table('website_resolution_attempts')->where('resolution_id', $resolution->id)->where('attempt_number', 2)->count());
    }

    public function test_resolution_lookup_and_review_are_tenant_isolated_and_request_replay_is_idempotent(): void
    {
        [$tenantA, $userA, $candidate] = $this->fixture('Noura Boutique');
        [$tenantB, $userB] = $this->fixture('Other Tenant Company');
        Sanctum::actingAs($userA); Queue::fake();
        $first = $this->withHeader('X-Tenant-ID', $tenantA->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'same-resolution-key'])->assertAccepted();
        $replay = $this->withHeader('X-Tenant-ID', $tenantA->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'same-resolution-key'])->assertAccepted();
        self::assertSame($first->json('resolution.id'), $replay->json('resolution.id'));
        $this->withHeader('X-Tenant-ID', $tenantA->id)->postJson('/api/v1/discovery/candidates/'.Str::uuid().'/website-resolution', ['idempotency_key' => 'same-resolution-key'])->assertUnprocessable();
        Sanctum::actingAs($userB);
        $this->withHeader('X-Tenant-ID', $tenantB->id)->getJson('/api/v1/discovery/website-resolutions/'.$first->json('resolution.id'))->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenantB->id)->postJson('/api/v1/discovery/website-resolutions/'.$first->json('resolution.id').'/review', ['action' => 'mark_unresolved'])->assertNotFound();
        self::assertSame(1, DB::table('website_resolutions')->where('tenant_id', $tenantA->id)->count());
    }

    public function test_run_metrics_are_tenant_scoped_and_include_resolution_funnel_counts(): void
    {
        [$tenant, $user, $candidate, $run] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/discovery/website-resolutions/metrics?run_id='.$run)
            ->assertOk()->assertJsonPath('website_less', 1)->assertJsonPath('attempted', 0)
            ->assertJsonPath('candidate_domains_found', 0)->assertJsonPath('resolved', 0)->assertJsonPath('ambiguous', 0)
            ->assertJsonPath('unresolved', 0)->assertJsonPath('failed', 0)->assertJsonPath('verified_after_resolution', 0)
            ->assertJsonPath('eligible_for_analysis', 0);

        [$otherTenant, $otherUser] = $this->fixture('Other Tenant Company');
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/discovery/website-resolutions/metrics?run_id='.$run)->assertNotFound();
    }

    public function test_local_index_discovers_and_resolves_a_website_less_business_without_wikidata(): void
    {
        [$tenant, $user, $candidate, $run] = $this->fixture('ABC Furniture LLC', ['phone' => '+971 4 555 1234']);
        Sanctum::actingAs($user); Queue::fake();
        $this->ingestIndex([[
            'canonical_url' => 'https://abc-furniture.ae/', 'organization_name' => 'ABC Furniture', 'page_title' => 'ABC Furniture Dubai',
            'description' => 'Furniture showroom in Dubai', 'country' => 'UAE', 'city' => 'Dubai', 'phone_values' => ['+971 4 555 1234'],
            'source' => 'verified_open_discovery', 'source_reference' => 'osm:verified:abc-furniture', 'source_timestamp' => now(),
        ]]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404)
            : Http::response('<html><head><script type="application/ld+json">{"@type":"LocalBusiness","name":"ABC Furniture","telephone":"+971 4 555 1234","address":{"addressLocality":"Dubai","addressCountry":"UAE"}}</script></head><body>ABC Furniture Dubai</body></html>', 200));

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'abc-furniture-index-1'])->assertAccepted()->json();
        $resolutionId = $response['resolution']['id'];
        $this->runResolutionJob($tenant->id, $resolutionId);
        $this->assertDatabaseHas('website_resolution_evidence', ['resolution_id' => $resolutionId, 'signal' => 'local_index_discovery', 'points' => 0]);
        $indexedEvidence = DB::table('website_resolution_evidence')->where('resolution_id', $resolutionId)->where('signal', 'local_index_discovery')->first();
        self::assertSame(100, data_get(json_decode($indexedEvidence->details, true), 'search_rank'));
        self::assertSame('AMBIGUOUS', DB::table('website_resolutions')->where('id', $resolutionId)->value('state'), 'One high-confidence result remains human-controlled when auto-confirmation is disabled.');
        $candidateDomain = DB::table('website_resolution_candidates')->where('resolution_id', $resolutionId)->value('normalized_domain');
        self::assertSame('abc-furniture.ae', $candidateDomain);
        self::assertSame('verified_open_discovery', DB::table('web_index_documents')->where('normalized_domain', $candidateDomain)->value('source'));

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/website-resolutions/{$resolutionId}/review", [
            'action' => 'confirm', 'candidate_id' => DB::table('website_resolution_candidates')->where('resolution_id', $resolutionId)->value('id'),
        ])->assertOk()->assertJsonPath('resolution.state', 'RESOLVED');
        (new VerifyDiscoveryCandidateJob($tenant->id, $run, $candidate))->handle(app(UrlPolicy::class), app(DomainNormalizer::class), app(RobotsRules::class));
        (new AnalyzeAndScoreDiscoveryCandidateJob($tenant->id, $candidate))->handle(app(\App\Crawling\CrawlerService::class), app(\App\Agents\AgentOrchestrator::class));
        self::assertSame('reviewable', DB::table('discovery_candidates')->where('id', $candidate)->value('lifecycle_status'));
        $this->assertDatabaseMissing('campaign_recipients', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('outbound_messages', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('workflow_approvals', ['tenant_id' => $tenant->id, 'action' => 'SEND_OUTREACH']);
    }

    public function test_local_index_does_not_assign_a_same_name_business_from_the_wrong_country(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('ABC Furniture');
        Sanctum::actingAs($user); Queue::fake();
        $this->ingestIndex([['canonical_url' => 'https://abc-furniture.co.uk/', 'organization_name' => 'ABC Furniture', 'page_title' => 'ABC Furniture London',
            'country' => 'United Kingdom', 'city' => 'London', 'source' => 'verified_open_discovery', 'source_reference' => 'osm:verified:abc-uk']]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404)
            : Http::response('<html><head><script type="application/ld+json">{"@type":"Organization","name":"ABC Furniture","address":{"addressLocality":"London","addressCountry":"United Kingdom"}}</script></head><body>ABC Furniture London</body></html>', 200));
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'abc-wrong-country-1'])->assertAccepted()->json();
        $this->runResolutionJob($tenant->id, $response['resolution']['id']);
        self::assertSame('rejected', DB::table('website_resolution_candidates')->where('resolution_id', $response['resolution']['id'])->value('status'));
        self::assertSame('UNRESOLVED', DB::table('website_resolutions')->where('id', $response['resolution']['id'])->value('state'));
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
        $this->assertDatabaseHas('website_resolution_evidence', ['resolution_id' => $response['resolution']['id'], 'signal' => 'country_contradiction', 'polarity' => 'negative']);
    }

    public function test_directory_index_record_yields_the_linked_business_domain_not_the_directory_domain(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake();
        $this->ingestIndex([['canonical_url' => 'https://directory.example/business/noura', 'normalized_domain' => 'directory.example',
            'organization_name' => 'Noura Boutique', 'page_title' => 'Noura Boutique Dubai listing', 'country' => 'UAE', 'city' => 'Dubai',
            'document_type' => 'directory', 'outbound_business_links' => [['url' => 'https://noura-boutique.ae/', 'organization_name' => 'Noura Boutique', 'country' => 'UAE', 'city' => 'Dubai']],
            'source' => 'public_directory', 'source_reference' => 'https://directory.example/business/noura']]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404)
            : Http::response('<html><head><script type="application/ld+json">{"@type":"Organization","name":"Noura Boutique","address":{"addressLocality":"Dubai","addressCountry":"UAE"}}</script></head><body>Noura Boutique Dubai</body></html>', 200));
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-directory-link-1'])->assertAccepted()->json();
        $this->runResolutionJob($tenant->id, $response['resolution']['id']);
        self::assertSame(['noura-boutique.ae'], DB::table('website_resolution_candidates')->where('resolution_id', $response['resolution']['id'])->pluck('normalized_domain')->all());
        $this->assertDatabaseHas('website_resolution_evidence', ['resolution_id' => $response['resolution']['id'], 'signal' => 'directory_business_link']);
        self::assertSame('AMBIGUOUS', DB::table('website_resolutions')->where('id', $response['resolution']['id'])->value('state'));
    }

    public function test_local_index_keeps_two_comparable_domains_ambiguous_and_returns_none_for_no_match(): void
    {
        [$tenant, $user, $candidate] = $this->fixture('Noura Boutique');
        Sanctum::actingAs($user); Queue::fake();
        $this->ingestIndex([
            ['canonical_url' => 'https://noura-one.ae/', 'organization_name' => 'Noura Boutique', 'country' => 'UAE', 'city' => 'Dubai', 'source' => 'verified_open_discovery', 'source_reference' => 'noura-one'],
            ['canonical_url' => 'https://noura-two.ae/', 'organization_name' => 'Noura Boutique', 'country' => 'UAE', 'city' => 'Dubai', 'source' => 'verified_open_discovery', 'source_reference' => 'noura-two'],
        ]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404)
            : Http::response('<html><head><script type="application/ld+json">{"@type":"Organization","name":"Noura Boutique","address":{"addressLocality":"Dubai","addressCountry":"UAE"}}</script></head></html>', 200));
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'noura-two-index-1'])->assertAccepted()->json();
        $this->runResolutionJob($tenant->id, $response['resolution']['id']);
        self::assertSame('AMBIGUOUS', DB::table('website_resolutions')->where('id', $response['resolution']['id'])->value('state'));
        self::assertSame(2, DB::table('website_resolution_candidates')->where('resolution_id', $response['resolution']['id'])->count());
        self::assertNull(DB::table('discovery_candidates')->where('id', $candidate)->value('normalized_domain'));
        self::assertSame([], app(\App\WebsiteResolution\LocalWebIndexSearchService::class)->search(['business_name' => 'No Such Company', 'city' => 'Dubai', 'country' => 'UAE']));
    }

    public function test_local_index_ingestion_is_content_hash_idempotent_and_domain_aggregated(): void
    {
        $record = ['canonical_url' => 'https://index-acme.ae/about', 'organization_name' => 'Acme Group LLC', 'page_title' => 'Acme Group',
            'country' => 'United Arab Emirates', 'city' => 'Dubai', 'source' => 'verified_open_discovery', 'source_reference' => 'acme:about'];
        $first = $this->ingestIndex([$record]);
        $second = $this->ingestIndex([$record]);
        self::assertSame(1, $first['inserted']);
        self::assertSame(1, $second['unchanged']);
        self::assertSame(1, DB::table('web_index_documents')->where('normalized_domain', 'index-acme.ae')->count());
        $this->ingestIndex([array_merge($record, ['canonical_url' => 'https://index-acme.ae/contact', 'page_title' => 'Contact Acme Group'])]);
        self::assertSame(2, DB::table('web_index_documents')->where('normalized_domain', 'index-acme.ae')->count(), 'Useful pages can share a domain and are aggregated into one resolution candidate.');
        $identity = ['business_name' => 'Acme Group', 'city' => 'Dubai', 'country' => 'UAE'];
        $source = app(\App\WebsiteResolution\LocalWebIndexResolutionSource::class)->find($identity);
        self::assertCount(1, $source);
        self::assertSame('index-acme.ae', $source[0]['existing_domain']);
        self::assertSame(95, $source[0]['search_rank']);
        self::assertContains('city', $source[0]['matching_fields']);
        self::assertSame('acme:about', $source[0]['index_provenance'][0]['source_reference']);
    }

    public function test_index_ingestion_command_requires_an_explicit_allowed_source_and_bounded_limit(): void
    {
        $this->artisan('web-index:ingest')->assertExitCode(2);
        $this->artisan('web-index:ingest', ['--source' => 'unknown', '--limit' => 5])->assertExitCode(2);
        $this->artisan('web-index:ingest', ['--source' => 'verified_discovery', '--limit' => 501])->assertExitCode(2);
        $this->artisan('web-index:ingest', ['--source' => 'verified_discovery', '--limit' => 10])->assertExitCode(0);
    }

    private function ingestIndex(array $records): array
    {
        $source = new class($records) implements WebIndexIngestionSourceInterface {
            public function __construct(private array $rows) {}
            public function name(): string { return 'test_fixture'; }
            public function documents(int $limit, array $options = [], ?array $cursor = null): iterable { yield from array_slice($this->rows, 0, $limit); }
            public function metrics(): array { return []; }
        };
        return app(LocalWebIndexIngestionService::class)->ingest($source, 50);
    }

    private function runResolutionJob(string $tenantId, string $resolutionId): void
    {
        (new ResolveWebsiteCandidateJob($tenantId, $resolutionId))->handle(app(WebsiteResolutionSourceRegistry::class), app(DomainNormalizer::class), app(UrlPolicy::class), app(RobotsRules::class), app(WebsiteIdentityPageExtractor::class), app(BusinessWebsiteIdentityMatcher::class), app(DirectoryDomainClassifier::class));
    }

    private function fixture(string $name, array $extraTags = []): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => Str::slug($name), 'slug' => Str::slug($name).'-'.Str::random(5), 'status' => 'active']);
        $user = User::create(['name' => 'Test Owner', 'email' => Str::random(10).'@example.test', 'password' => bcrypt('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $search = (string) Str::uuid(); $run = (string) Str::uuid(); $candidate = (string) Str::uuid();
        DB::table('discovery_searches')->insert(['id' => $search, 'tenant_id' => $tenant->id, 'name' => 'Resolution acceptance', 'status' => 'active', 'source' => 'open_web', 'max_candidates' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discovery_runs')->insert(['id' => $run, 'tenant_id' => $tenant->id, 'discovery_search_id' => $search, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discovery_candidates')->insert(['id' => $candidate, 'tenant_id' => $tenant->id, 'discovery_run_id' => $run, 'company_name' => $name,
            'city' => 'Dubai', 'country' => 'UAE', 'industry' => 'Retail', 'source' => 'openstreetmap', 'source_reference' => 'osm:node:'.Str::random(8),
            'discovered_at' => now(), 'verification_state' => 'not_required', 'lifecycle_status' => 'discovered', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discovery_candidate_sources')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'candidate_id' => $candidate, 'discovery_run_id' => $run,
            'source' => 'openstreetmap', 'source_reference' => 'osm:node:'.Str::random(8), 'discovered_at' => now(),
            'source_metadata' => json_encode(['tags' => array_merge(['name' => $name, 'addr:city' => 'Dubai', 'addr:country' => 'UAE'], $extraTags)]), 'created_at' => now(), 'updated_at' => now()]);
        return [$tenant, $user, $candidate, $run];
    }
}
