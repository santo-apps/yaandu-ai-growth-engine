<?php

namespace Tests\Feature;

use App\Discovery\DomainNormalizer;
use App\WebsiteResolution\BusinessEmailDomainCandidateSource;
use App\WebsiteResolution\BusinessIdentity;
use App\WebsiteResolution\CandidateDiscoveryBatch;
use App\WebsiteResolution\CandidateDiscoveryContext;
use App\WebsiteResolution\CandidateDiscoveryRanker;
use App\WebsiteResolution\CandidateDiscoveryResultClassifier;
use App\WebsiteResolution\CandidateDomainDiscoveryService;
use App\WebsiteResolution\CandidateDomainDiscoverySourceInterface;
use App\WebsiteResolution\CandidateDomainDiscoverySourceRegistry;
use App\WebsiteResolution\CandidateDomainQueryPlanner;
use App\WebsiteResolution\CandidateDomainUrlNormalizer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductizedCandidateDomainDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_planner_is_bounded_and_requires_useful_evidence(): void
    {
        config(['candidate_discovery.max_queries_per_business' => 5]);
        $identity = BusinessIdentity::fromArray(['business_name' => 'Acme Furniture', 'city' => 'Dubai', 'country' => 'UAE',
            'category' => 'Furniture', 'region' => 'Dubai', 'public_phone' => '+971 4 555 1234', 'alternate_names' => ['Acme Home']]);
        $queries = app(CandidateDomainQueryPlanner::class)->plan($identity);

        self::assertCount(5, $queries);
        self::assertStringContainsString('Acme Furniture', $queries[0]);
        self::assertStringContainsString('Dubai', $queries[0]);
        self::assertSame([], app(CandidateDomainQueryPlanner::class)->plan(BusinessIdentity::fromArray(['business_name' => 'Acme'])));
    }

    public function test_wikidata_open_search_uses_bounded_api_and_preserves_query_evidence(): void
    {
        Http::fake([
            'https://www.wikidata.org/w/api.php?action=wbsearchentities*' => Http::response(['search' => [
                ['id' => 'Q123', 'label' => 'Acme Furniture', 'description' => 'Furniture retailer in Dubai'],
            ]], 200),
            'https://www.wikidata.org/w/api.php?action=wbgetentities*' => Http::response(['entities' => [
                'Q123' => ['claims' => ['P856' => [['mainsnak' => ['datavalue' => ['value' => 'https://acme-furniture.ae/?utm_source=wikidata']]]]]],
            ]], 200),
        ]);
        $source = app(\App\WebsiteResolution\WikidataCandidateDomainDiscoverySource::class);
        $batch = $source->discover(BusinessIdentity::fromArray(['business_name' => 'Acme Furniture', 'city' => 'Dubai', 'country' => 'UAE', 'category' => 'Furniture']),
            new CandidateDiscoveryContext((string) Str::uuid(), (string) Str::uuid(), 5, 10, 10, PHP_INT_MAX));

        self::assertNotEmpty($batch->queries);
        self::assertSame('https://acme-furniture.ae/?utm_source=wikidata', $batch->results[0]['target_url']);
        self::assertSame('Q123', $batch->results[0]['source_reference']);
        self::assertSame(0, $batch->results[0]['evidence'][0]['points'], 'Search rank and entity search must not create identity confidence.');
        self::assertLessThanOrEqual(10, count($batch->results));
        self::assertGreaterThan(1, count(Http::recorded()));
        self::assertLessThanOrEqual(6, count(Http::recorded()));
    }

    public function test_wikidata_http_200_api_error_is_a_source_failure_not_a_false_empty_result(): void
    {
        Http::fake(['https://www.wikidata.org/w/api.php*' => Http::response(['error' => [
            'code' => 'maxlag', 'info' => 'The public service is behind.',
        ]], 200)]);
        $batch = app(\App\WebsiteResolution\WikidataCandidateDomainDiscoverySource::class)->discover(
            BusinessIdentity::fromArray(['business_name' => 'Aster Medical Centre', 'city' => 'Dubai', 'country' => 'UAE']),
            new CandidateDiscoveryContext((string) Str::uuid(), (string) Str::uuid(), 2, 5, 10, PHP_INT_MAX),
        );
        self::assertNotEmpty($batch->failures);
        self::assertSame([], $batch->results);
    }

    public function test_external_source_failure_isolated_and_raw_results_are_separate_from_ranked_candidates(): void
    {
        [$tenant, $resolution] = $this->fixture('Acme Furniture');
        $good = $this->source('open_fixture', [
            $this->searchResult('https://acme-furniture.ae/?utm_source=test', 'https://search.example/acme', 'Acme Furniture Dubai', 'Exact business name · Dubai', 4, 'fixture:acme'),
            $this->searchResult('https://www.acme-furniture.ae/', 'https://search.example/acme-again', 'Acme Furniture', 'Independent match', 1, 'fixture:acme-duplicate'),
        ]);
        $failed = $this->source('unavailable_fixture', [], [], ['source_timeout']);
        $service = new CandidateDomainDiscoveryService(new CandidateDomainDiscoverySourceRegistry([$good, $failed]),
            app(CandidateDomainUrlNormalizer::class), app(CandidateDiscoveryRanker::class));

        $result = $service->discover($tenant->id, $resolution);

        self::assertSame('PARTIALLY_COMPLETED', $result['status']);
        self::assertSame(1, $result['candidates']);
        self::assertSame(2, $result['results']);
        self::assertSame('PARTIALLY_COMPLETED', DB::table('website_resolutions')->where('id', $resolution)->value('discovery_status'));
        self::assertSame(1, DB::table('website_resolution_candidates')->where('tenant_id', $tenant->id)->where('resolution_id', $resolution)->count());
        self::assertSame(2, DB::table('website_resolution_search_results')->where('tenant_id', $tenant->id)->where('resolution_id', $resolution)->count());
        self::assertDatabaseHas('website_resolution_candidates', ['resolution_id' => $resolution, 'normalized_domain' => 'acme-furniture.ae', 'discovery_rank' => 1, 'score' => 0, 'status' => 'unverified']);
        self::assertDatabaseHas('website_resolution_search_results', ['resolution_id' => $resolution, 'result_type' => 'POSSIBLE_OFFICIAL_SITE', 'target_type' => 'POSSIBLE_OFFICIAL_SITE']);
        self::assertDatabaseHas('website_resolution_attempts', ['resolution_id' => $resolution, 'source' => 'unavailable_fixture', 'state' => 'partial']);
        self::assertDatabaseMissing('campaign_recipients', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('outbound_messages', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('workflow_approvals', ['tenant_id' => $tenant->id, 'action' => 'SEND_OUTREACH']);
    }

    public function test_search_order_is_not_identity_score_and_results_are_idempotent(): void
    {
        [$tenant, $resolution] = $this->fixture('Acme Furniture');
        $source = $this->source('fixture', [
            $this->searchResult('https://rank-one.example/', 'https://search.example/1', 'Result One', 'Search rank one', 1, 'fixture:rank-one'),
            $this->searchResult('https://rank-two.example/', 'https://search.example/2', 'Result Two', 'Search rank two', 2, 'fixture:rank-two'),
        ]);
        $service = new CandidateDomainDiscoveryService(new CandidateDomainDiscoverySourceRegistry([$source]), app(CandidateDomainUrlNormalizer::class), app(CandidateDiscoveryRanker::class));

        $first = $service->discover($tenant->id, $resolution);
        DB::table('website_resolutions')->where('id', $resolution)->update(['state' => 'PENDING']);
        $second = $service->discover($tenant->id, $resolution);

        self::assertSame(2, $first['candidates']);
        self::assertSame(2, $second['candidates']);
        self::assertSame(2, DB::table('website_resolution_candidates')->where('resolution_id', $resolution)->count());
        self::assertSame(2, DB::table('website_resolution_search_results')->where('resolution_id', $resolution)->count());
        self::assertSame([0, 0], DB::table('website_resolution_candidates')->where('resolution_id', $resolution)->orderBy('discovery_rank')->pluck('score')->all());
        self::assertSame([1, 2], DB::table('website_resolution_candidates')->where('resolution_id', $resolution)->orderBy('discovery_rank')->pluck('discovery_rank')->all());
    }

    public function test_no_candidates_is_distinct_from_all_sources_failed_and_weak_identity_is_skipped(): void
    {
        [$tenant, $resolution] = $this->fixture('Acme Furniture');
        $empty = new CandidateDomainDiscoveryService(new CandidateDomainDiscoverySourceRegistry([$this->source('empty', [])]),
            app(CandidateDomainUrlNormalizer::class), app(CandidateDiscoveryRanker::class));
        self::assertSame('NO_CANDIDATES', $empty->discover($tenant->id, $resolution)['status']);

        [$tenantB, $resolutionB] = $this->fixture('Acme Furniture');
        $failed = new CandidateDomainDiscoveryService(new CandidateDomainDiscoverySourceRegistry([$this->source('failed', [], [], ['timeout'])]),
            app(CandidateDomainUrlNormalizer::class), app(CandidateDiscoveryRanker::class));
        self::assertSame('FAILED', $failed->discover($tenantB->id, $resolutionB)['status']);

        [$tenantC, $resolutionC] = $this->fixture('Acme Furniture', ['city' => null, 'country' => null, 'category' => null, 'address' => null]);
        $skipped = $empty->discover($tenantC->id, $resolutionC);
        self::assertSame('NO_CANDIDATES', $skipped['status']);
        self::assertSame(0, $skipped['source_calls']);
        self::assertSame(0, DB::table('website_resolution_attempts')->where('resolution_id', $resolutionC)->count());
    }

    public function test_directory_social_marketplace_and_unsafe_urls_never_become_domain_candidates(): void
    {
        $ranker = app(CandidateDiscoveryRanker::class);
        $results = [];
        foreach (['https://www.yelp.com/biz/acme', 'https://www.instagram.com/acme', 'https://www.noon.com/acme', 'file:///etc/passwd', 'https://user:pass@acme.example/'] as $index => $url) {
            $results[] = ['source' => 'fixture', 'target_url' => $url, 'rank' => $index + 1, 'evidence' => []];
        }
        self::assertSame([], $ranker->rank($results));
        self::assertSame('DIRECTORY', app(CandidateDiscoveryResultClassifier::class)->classify('https://directory.example/directory/business/acme'));
        self::assertSame('SOCIAL', app(CandidateDiscoveryResultClassifier::class)->classify('https://linkedin.com/company/acme'));
        self::assertSame('MARKETPLACE', app(CandidateDiscoveryResultClassifier::class)->classify('https://noon.com/item'));
        self::assertSame('DOCUMENT', app(CandidateDiscoveryResultClassifier::class)->classify('https://example.com/report.pdf'));
    }

    public function test_deterministic_provider_supports_required_local_fixture_scenarios(): void
    {
        $name = app(\App\WebsiteResolution\BusinessIdentityNormalizer::class)->name('Fixture Furniture');
        $source = app(\App\WebsiteResolution\DeterministicCandidateDomainDiscoverySource::class);
        $context = new CandidateDiscoveryContext((string) Str::uuid(), (string) Str::uuid(), 5, 10, 10, PHP_INT_MAX);
        config(['candidate_discovery.deterministic_fixtures' => [
            $name => ['mode' => 'multiple', 'url' => 'https://fixture-one.example', 'second_url' => 'https://fixture-two.example'],
        ]]);
        $multiple = $source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context);
        self::assertCount(2, $multiple->results);

        config(['candidate_discovery.deterministic_fixtures' => [$name => ['mode' => 'duplicate_domain', 'url' => 'https://fixture-one.example']]]);
        self::assertCount(1, app(CandidateDiscoveryRanker::class)->rank($source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context)->results));

        config(['candidate_discovery.deterministic_fixtures' => [$name => ['mode' => 'directory', 'target_url' => 'https://fixture-one.example']]]);
        self::assertCount(1, app(CandidateDiscoveryRanker::class)->rank($source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context)->results));

        config(['candidate_discovery.deterministic_fixtures' => [$name => ['mode' => 'social']]]);
        self::assertSame([], app(CandidateDiscoveryRanker::class)->rank($source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context)->results));

        config(['candidate_discovery.deterministic_fixtures' => [$name => ['mode' => 'malformed_result']]]);
        self::assertSame([], app(CandidateDiscoveryRanker::class)->rank($source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context)->results));

        config(['candidate_discovery.deterministic_fixtures' => [$name => ['mode' => 'timeout']]]);
        try { $source->discover(BusinessIdentity::fromArray(['business_name' => 'Fixture Furniture', 'city' => 'Dubai']), $context); self::fail('Timeout fixture must fail.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
    }

    public function test_url_normalization_strips_tracking_only_and_rejects_unsafe_schemes_and_credentials(): void
    {
        $normalizer = app(CandidateDomainUrlNormalizer::class);
        $normalized = $normalizer->normalize('HTTPS://WWW.Example.com/path?utm_campaign=one&keep=2#section');
        self::assertSame('example.com', $normalized['normalized_domain']);
        self::assertSame('https://example.com/path?keep=2', $normalized['normalized_url']);
        foreach (['file:///etc/passwd', 'javascript:alert(1)', 'https://user:pass@example.com', 'https://127.0.0.1/', 'https://example.com.'] as $unsafe) {
            try { $normalizer->normalize($unsafe); self::fail('Expected unsafe candidate URL to fail.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function test_public_email_domain_is_evidence_but_generic_mail_provider_is_never_candidate(): void
    {
        config(['candidate_discovery.generic_email_domains' => ['gmail.com', 'outlook.com']]);
        $source = app(BusinessEmailDomainCandidateSource::class);
        $identity = BusinessIdentity::fromArray(['business_name' => 'Acme Furniture', 'city' => 'Dubai', 'country' => 'UAE', 'public_email' => 'sales@acme-furniture.ae']);
        $batch = $source->discover($identity, new CandidateDiscoveryContext((string) Str::uuid(), (string) Str::uuid(), 5, 10, 10, PHP_INT_MAX));
        self::assertSame('https://acme-furniture.ae/', $batch->results[0]['target_url']);
        self::assertSame('email_domain_match', $batch->results[0]['evidence'][0]['signal']);
        $generic = BusinessIdentity::fromArray(['business_name' => 'Acme Furniture', 'city' => 'Dubai', 'country' => 'UAE', 'public_email' => 'acme@gmail.com']);
        self::assertSame([], $source->discover($generic, new CandidateDiscoveryContext((string) Str::uuid(), (string) Str::uuid(), 5, 10, 10, PHP_INT_MAX))->results);
    }

    public function test_resolution_request_is_tenant_scoped_idempotent_and_uses_dedicated_queue(): void
    {
        [$tenant, $candidate, $user] = $this->fixture('Acme Furniture', [], true);
        Sanctum::actingAs($user);
        Queue::fake();
        $first = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'acme-domain-find-1'])->assertAccepted();
        $second = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/discovery/candidates/{$candidate}/website-resolution", ['idempotency_key' => 'acme-domain-find-1'])->assertAccepted();
        self::assertSame($first->json('resolution.id'), $second->json('resolution.id'));
        Queue::assertPushed(\App\Jobs\RunCandidateDomainDiscoveryJob::class, 1);
        Queue::assertPushedOn('candidate-discovery', \App\Jobs\RunCandidateDomainDiscoveryJob::class);
        self::assertDatabaseMissing('campaign_recipients', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('outbound_messages', ['tenant_id' => $tenant->id]);
    }

    public function test_prospect_360_find_website_creates_one_tenant_scoped_candidate_and_queues_discovery(): void
    {
        [$tenant, , $user] = $this->fixture('Acme Furniture', [], true);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Acme Furniture Dubai', 'industry' => 'Furniture',
            'location' => 'Dubai, UAE', 'source' => 'manual', 'status' => 'new']);
        Sanctum::actingAs($user);
        Queue::fake();

        $first = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/companies/{$company->id}/website-discovery", ['idempotency_key' => 'prospect-find-site-001'])->assertAccepted();
        $second = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/companies/{$company->id}/website-discovery", ['idempotency_key' => 'prospect-find-site-002'])->assertAccepted();
        $detail = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/companies/{$company->id}/website-discovery")->assertOk();

        self::assertSame($first->json('resolution_id'), $second->json('resolution_id'));
        self::assertSame($company->id, $detail->json('candidate.company_id'));
        self::assertSame(1, DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->count());
        Queue::assertPushedOn('candidate-discovery', \App\Jobs\RunCandidateDomainDiscoveryJob::class);
        Queue::assertPushed(\App\Jobs\RunCandidateDomainDiscoveryJob::class, 1);
        self::assertDatabaseMissing('campaign_recipients', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('outbound_messages', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('meeting_bookings', ['tenant_id' => $tenant->id]);
        self::assertDatabaseMissing('proposals', ['tenant_id' => $tenant->id]);
    }

    public function test_candidate_discovery_operations_are_owner_scoped_and_tenant_scoped(): void
    {
        [$tenant, , $user] = $this->fixture('Acme Furniture', [], true);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations/candidate-discovery')->assertOk()
            ->assertJsonPath('totals.runs', 0)->assertJsonPath('totals.candidate_domains', 0);

        $member = User::create(['name' => 'Discovery Member', 'email' => Str::random(10).'@example.test', 'password' => bcrypt('password')]);
        $tenant->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations/candidate-discovery')->assertForbidden();
    }

    private function fixture(string $name, array $identityOverrides = [], bool $includeUser = false): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'active']);
        $user = User::create(['name' => 'Candidate Test Owner', 'email' => Str::random(10).'@example.test', 'password' => bcrypt('password')]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        $searchId = (string) Str::uuid(); $runId = (string) Str::uuid(); $candidateId = (string) Str::uuid(); $resolutionId = (string) Str::uuid();
        DB::table('discovery_searches')->insert(['id' => $searchId, 'tenant_id' => $tenant->id, 'name' => 'Candidate discovery', 'status' => 'active', 'source' => 'open_web', 'max_candidates' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'discovery_search_id' => $searchId, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discovery_candidates')->insert(['id' => $candidateId, 'tenant_id' => $tenant->id, 'discovery_run_id' => $runId, 'company_name' => $name,
            'city' => $identityOverrides['city'] ?? 'Dubai', 'country' => $identityOverrides['country'] ?? 'UAE', 'industry' => $identityOverrides['category'] ?? 'Furniture',
            'source' => 'openstreetmap', 'source_reference' => 'osm:'.Str::uuid(), 'discovered_at' => now(), 'verification_state' => 'not_required',
            'lifecycle_status' => 'website_not_found', 'created_at' => now(), 'updated_at' => now()]);
        if (! $includeUser) {
            DB::table('website_resolutions')->insert(['id' => $resolutionId, 'tenant_id' => $tenant->id, 'candidate_id' => $candidateId, 'discovery_run_id' => $runId,
                'requested_by' => $user->id, 'state' => 'PENDING', 'discovery_status' => 'PENDING', 'identity_snapshot' => json_encode(array_merge([
                    'business_name' => $name, 'city' => 'Dubai', 'country' => 'UAE', 'category' => 'Furniture', 'source_provenance' => [],
                ], $identityOverrides)), 'idempotency_key' => 'idempotency-'.$resolutionId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $includeUser ? [$tenant, $candidateId, $user] : [$tenant, $resolutionId];
    }

    private function source(string $name, array $results = [], array $queries = [], array $failures = []): CandidateDomainDiscoverySourceInterface
    {
        return new class($name, $results, $queries, $failures) implements CandidateDomainDiscoverySourceInterface {
            public function __construct(private string $sourceName, private array $rows, private array $queryRows, private array $errors) {}
            public function name(): string { return $this->sourceName; }
            public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch
            { return new CandidateDiscoveryBatch(array_slice($this->rows, 0, $context->maxRawResultsPerSource), $this->queryRows ?: ['"'.$identity->businessName.'" "'.$identity->city.'"'], ['cache_hits' => 0], $this->errors); }
        };
    }

    private function searchResult(string $target, string $resultUrl, string $title, string $snippet, int $rank, string $reference): array
    {
        return ['query' => '"Acme Furniture" "Dubai"', 'result_url' => $resultUrl, 'target_url' => $target,
            'title' => $title, 'snippet' => $snippet, 'rank' => $rank, 'source_reference' => $reference,
            'evidence' => [['signal' => 'name_match', 'polarity' => 'neutral', 'points' => 0, 'summary' => $title, 'details' => []]]];
    }
}
