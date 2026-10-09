<?php

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\Company;
use App\Models\User;
use App\WebsiteIntelligence\WebsiteIntelligencePilotPrompt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Redis\RedisManager;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\UploadedFile;

/**
 * Opt-in integration test. Run only against the configured, migrated local PostgreSQL
 * database with Redis available. Each test uses a unique Redis prefix, so Horizon
 * can remain online on the normal application prefix.
 */
final class PostgresRedisDiscoveryVerificationTest extends TestCase
{
    public function test_explicit_website_discovery_persists_raw_evidence_and_replays_idempotently_through_redis(): void
    {
        if (! (bool) env('YAANDU_POSTGRES_REDIS_INTEGRATION', false)) {
            self::markTestSkipped('Set YAANDU_POSTGRES_REDIS_INTEGRATION=true to run against the dedicated local PostgreSQL/Redis integration environment.');
        }
        self::assertSame('pgsql', DB::connection()->getDriverName(), 'This suite must execute against PostgreSQL.');
        self::assertSame('redis', config('queue.default'), 'This suite must enqueue through Redis.');
        $this->isolateRedisQueue();

        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'PostgreSQL Candidate Discovery Integration',
            'slug' => 'pg-candidate-'.Str::lower(Str::random(12)), 'status' => 'active']);
        $user = User::create(['name' => 'Candidate Integration Owner', 'email' => 'pg-candidate-'.Str::lower(Str::random(12)).'@example.test',
            'password' => Hash::make(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        config(['candidate_discovery.wikidata_search_enabled' => false, 'candidate_discovery.deterministic_fixtures' => [
            'integration furniture' => ['mode' => 'directory', 'result_url' => 'https://directory.example/directory/listing/integration-furniture',
                'target_url' => null, 'title' => 'Directory evidence fixture', 'snippet' => 'Untrusted deterministic evidence.'],
        ]]);
        $idempotencyKey = 'pg-redis-candidate-'.Str::lower(Str::random(16));

        try {
            $company = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Integration Furniture',
                'location' => 'Dubai', 'industry' => 'Furniture', 'source' => 'integration_fixture', 'status' => 'new']);
            Sanctum::actingAs($user);
            $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/companies/'.$company->id.'/website-discovery', [
                'idempotency_key' => $idempotencyKey,
            ])->assertAccepted();
            $resolutionId = $response->json('resolution_id');
            $this->assertQueuedJob('candidate-discovery', \App\Jobs\RunCandidateDomainDiscoveryJob::class,
                ['tenantId' => $tenant->id, 'resolutionId' => $resolutionId]);
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'candidate-discovery', '--once' => true, '--tries' => 1]));
            $this->assertNoQueuedJob('candidate-discovery', \App\Jobs\RunCandidateDomainDiscoveryJob::class,
                ['tenantId' => $tenant->id, 'resolutionId' => $resolutionId]);

            $resolution = DB::table('website_resolutions')->where('tenant_id', $tenant->id)->where('id', $resolutionId)->first();
            self::assertSame('NO_CANDIDATES', $resolution->discovery_status, 'A directory page without an explicit external link remains evidence, not a candidate. Actual: '.json_encode([
                'resolution' => $resolution, 'candidates' => DB::table('website_resolution_candidates')->where('tenant_id', $tenant->id)->where('resolution_id', $resolutionId)->get()->toArray(),
                'raw' => DB::table('website_resolution_search_results')->where('tenant_id', $tenant->id)->where('resolution_id', $resolutionId)->get()->toArray(),
            ]));
            $rawResults = DB::table('website_resolution_search_results')->where('tenant_id', $tenant->id)->where('resolution_id', $resolutionId)->get();
            self::assertCount(1, $rawResults, 'The deterministic directory source must persist raw result evidence. Attempts: '.json_encode(
                DB::table('website_resolution_attempts')->where('tenant_id', $tenant->id)->where('resolution_id', $resolutionId)->get()->toArray()));
            self::assertDatabaseHas('website_resolution_search_results', ['tenant_id' => $tenant->id, 'resolution_id' => $resolutionId,
                'result_type' => 'DIRECTORY', 'target_url' => null]);

            $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/companies/'.$company->id.'/website-discovery', [
                'idempotency_key' => $idempotencyKey,
            ])->assertOk()->assertJsonPath('resolution_id', $resolutionId);
            $this->assertNoQueuedJob('candidate-discovery', \App\Jobs\RunCandidateDomainDiscoveryJob::class,
                ['tenantId' => $tenant->id, 'resolutionId' => $resolutionId], 'A completed idempotent replay must not enqueue a duplicate job.');
            self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
            self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
            self::assertSame(0, DB::table('meeting_bookings')->where('tenant_id', $tenant->id)->count());
            self::assertSame(0, DB::table('proposals')->where('tenant_id', $tenant->id)->count());
        } finally {
            $tenant->delete();
            $user->delete();
        }
    }

    public function test_authenticated_discovery_run_is_serialized_to_redis_and_verified_in_postgres(): void
    {
        if (! (bool) env('YAANDU_POSTGRES_REDIS_INTEGRATION', false)) {
            self::markTestSkipped('Set YAANDU_POSTGRES_REDIS_INTEGRATION=true to run against the dedicated local PostgreSQL/Redis integration environment.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName(), 'This suite must execute against PostgreSQL.');
        self::assertSame('redis', config('queue.default'), 'This suite must enqueue through Redis.');
        $this->isolateRedisQueue();
        config(['discovery.allow_deterministic' => true, 'ai.local_acceptance.enabled' => true,
            'pilot.allow_simulated_fixtures' => true,
            'ai.tasks.website_reasoning.provider' => 'deterministic', 'ai.tasks.website_reasoning.model' => 'local-acceptance-v1',
            'ai.tasks.lead_classification.provider' => 'deterministic', 'ai.tasks.lead_classification.model' => 'local-acceptance-v1']);

        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'PostgreSQL Discovery Integration',
            'slug' => 'pg-discovery-'.Str::lower(Str::random(12)), 'status' => 'active']);
        $user = User::create(['name' => 'Integration Owner', 'email' => 'pg-discovery-'.Str::lower(Str::random(12)).'@example.test',
            'password' => Hash::make(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        try {
            DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'task_key' => 'website_reasoning', 'provider' => 'deterministic', 'model' => 'local-acceptance-v1', 'enabled' => true,
                'parameters' => json_encode(['temperature' => 0, 'max_output_tokens' => 3500]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('prompt_templates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'agent_key' => 'WebsiteIntelligenceAgent', 'version' => 1, 'system_instruction' => WebsiteIntelligencePilotPrompt::SYSTEM_INSTRUCTION,
                'template' => WebsiteIntelligencePilotPrompt::TEMPLATE, 'schema_version' => WebsiteIntelligencePilotPrompt::SCHEMA_VERSION,
                'active' => true, 'status' => 'approved', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Sanctum::actingAs($user);
            $search = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/discovery/searches', [
                'name' => 'PostgreSQL queued verification regression', 'source' => 'deterministic_local',
                'locations' => ['UAE'], 'industries' => ['E-commerce / Retail'], 'max_candidates' => 1,
            ])->assertCreated()->json();
            $run = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/discovery/searches/'.$search['id'].'/runs', [
                'idempotency_key' => 'pg-integration-'.Str::uuid(),
            ])->assertAccepted()->json();

            // Exercise Laravel's serialized Redis queue boundary instead of calling the job directly.
            $this->assertQueuedJob('discovery', \App\Jobs\RunAgentJob::class,
                ['tenantId' => $tenant->id, 'runId' => $run['agent_run_id'], 'agentName' => 'DiscoveryAgent']);
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'discovery', '--once' => true, '--tries' => 1]));
            $candidate = DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('discovery_run_id', $run['id'])
                ->where('source_reference', 'fixture:new-high')->first();
            self::assertNotNull($candidate, 'The queued DiscoveryAgent must persist the candidate in PostgreSQL.');
            $this->assertQueuedJob('crawl', \App\Jobs\VerifyDiscoveryCandidateJob::class,
                ['tenantId' => $tenant->id, 'runId' => $run['id'], 'candidateId' => $candidate->id]);
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'crawl', '--once' => true, '--tries' => 1]));
            $this->assertQueuedJob('crawl', \App\Jobs\AnalyzeAndScoreDiscoveryCandidateJob::class,
                ['tenantId' => $tenant->id, 'candidateId' => $candidate->id]);
            $candidate = DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->first();
            self::assertSame('verified', $candidate->verification_state);
            self::assertSame('analyzing', $candidate->lifecycle_status);
            self::assertNotNull($candidate->company_id);
            self::assertDatabaseHas('website_scans', ['tenant_id' => $tenant->id, 'crawler_version' => 'discovery-prepromotion-v1', 'status' => 'completed']);

            // An already-linked existing-company duplicate is terminal; it must not leave the run stuck in "analyzing".
            $existingCompany = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'name' => 'Existing Northstar', 'normalized_domain' => 'northstar-retail.fixture.test',
                'source' => 'acceptance_seed', 'status' => 'new']);
            DB::table('discovery_candidates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'discovery_run_id' => $run['id'], 'company_id' => $existingCompany->id, 'company_name' => 'Existing Northstar',
                'original_url' => 'https://northstar-retail.fixture.test', 'normalized_domain' => 'northstar-retail.fixture.test',
                'source' => 'deterministic_local', 'source_reference' => 'fixture:existing-terminal', 'discovered_at' => now(),
                'lifecycle_status' => 'verified', 'verification_state' => 'verified', 'deduplication_state' => 'existing_company',
                'created_at' => now(), 'updated_at' => now()]);
            $this->assertQueuedJob('crawl', \App\Jobs\AnalyzeAndScoreDiscoveryCandidateJob::class,
                ['tenantId' => $tenant->id, 'candidateId' => $candidate->id]);
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'crawl', '--once' => true, '--tries' => 1]));
            $this->assertNoQueuedJob('crawl', \App\Jobs\AnalyzeAndScoreDiscoveryCandidateJob::class,
                ['tenantId' => $tenant->id, 'candidateId' => $candidate->id], 'No retry or duplicate analysis job for this candidate should remain.');
            self::assertSame('reviewable', DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->value('lifecycle_status'),
                'The test-owned analysis job must complete successfully.');
            self::assertSame('completed', DB::table('discovery_runs')->where('tenant_id', $tenant->id)->where('id', $run['id'])->value('status'));
            self::assertDatabaseHas('lead_scores', ['tenant_id' => $tenant->id, 'company_id' => $candidate->company_id]);
        } finally {
            // The fixture tenant is unique to this invocation; cascade delete removes only its data.
            $tenant->delete();
            $user->delete();
        }
    }

    public function test_sprint_seven_known_domain_import_is_tenant_scoped_and_processed_from_redis(): void
    {
        if (! (bool) env('YAANDU_POSTGRES_REDIS_INTEGRATION', false)) {
            self::markTestSkipped('Set YAANDU_POSTGRES_REDIS_INTEGRATION=true to run against the dedicated local PostgreSQL/Redis integration environment.');
        }
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame('redis', config('queue.default'));
        $this->isolateRedisQueue();

        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Sprint 7 import integration', 'slug' => 's7-import-'.Str::lower(Str::random(12)), 'status' => 'active']);
        $user = User::create(['name' => 'Sprint 7 Import Owner', 'email' => 's7-import-'.Str::lower(Str::random(12)).'@example.test', 'password' => Hash::make(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        try {
            $headers = ['X-Tenant-ID' => $tenant->id];
            $operations = $this->withHeaders($headers)->getJson('/api/v1/pilot/operations')->assertOk()->json();
            self::assertSame('available', $operations['redis']);
            self::assertContains($operations['horizon']['status'], ['running', 'inactive'],
                'The operations endpoint should report the real Horizon state without requiring a global shutdown.');
            $cohort = $this->withHeaders($headers)->postJson('/api/v1/pilot/cohorts', ['name' => 'Postgres Redis import integration', 'status' => 'active'])->assertCreated()->json();
            $preview = $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/preview', [
                'csv' => UploadedFile::fake()->createWithContent('s7-integration.csv', "business_name,website,country,source\nPG Integration Business,https://www.pg-import-fixture.test,IN,integration fixture\n"),
                'cohort_id' => $cohort['id'],
            ])->assertCreated()->json();
            $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/'.$preview['id'].'/confirm', ['idempotency_key' => 's7-pg-import-'.$tenant->id])->assertAccepted()->assertJsonPath('queued_rows', 1);
            $rowId = DB::table('prospect_import_rows')->where('tenant_id', $tenant->id)->where('batch_id', $preview['id'])->value('id');
            self::assertNotNull($rowId);
            $this->assertQueuedJob('intake', \App\Jobs\ProcessProspectImportRowJob::class, ['tenantId' => $tenant->id, 'rowId' => $rowId]);
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'intake', '--once' => true, '--tries' => 1]));
            $this->assertNoQueuedJob('intake', \App\Jobs\ProcessProspectImportRowJob::class, ['tenantId' => $tenant->id, 'rowId' => $rowId]);

            $company = DB::table('companies')->where('tenant_id', $tenant->id)->where('normalized_domain', 'pg-import-fixture.test')->first();
            self::assertNotNull($company);
            self::assertSame('user_supplied_import', DB::table('company_websites')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->value('source'));
            self::assertSame('unverified', DB::table('company_websites')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->value('verification_status'));
            self::assertSame('completed', DB::table('prospect_import_batches')->where('tenant_id', $tenant->id)->where('id', $preview['id'])->value('status'));
            $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/'.$preview['id'].'/confirm', ['idempotency_key' => 's7-pg-import-'.$tenant->id])->assertAccepted()->assertJsonPath('queued_rows', 0);
            $this->assertNoQueuedJob('intake', \App\Jobs\ProcessProspectImportRowJob::class,
                ['tenantId' => $tenant->id, 'rowId' => $rowId], 'Idempotent import replay must not enqueue a duplicate row job.');
            self::assertSame(1, DB::table('companies')->where('tenant_id', $tenant->id)->where('normalized_domain', 'pg-import-fixture.test')->count());

            $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other Sprint 7 tenant', 'slug' => 's7-other-'.Str::lower(Str::random(12)), 'status' => 'active']);
            $otherUser = User::create(['name' => 'Other Tenant', 'email' => 's7-other-'.Str::lower(Str::random(12)).'@example.test', 'password' => Hash::make(Str::random(32))]);
            $otherTenant->users()->attach($otherUser->id, ['role' => 'owner', 'status' => 'active']);
            Sanctum::actingAs($otherUser);
            $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/pilot/import-batches/'.$preview['id'])->assertNotFound();
            self::assertSame(0, DB::table('companies')->where('tenant_id', $otherTenant->id)->count());
            $otherTenant->delete(); $otherUser->delete();
        } finally {
            $tenant->delete();
            $user->delete();
        }
    }

    public function test_authenticated_review_history_appends_on_postgres_without_aggregate_row_lock(): void
    {
        if (! (bool) env('YAANDU_POSTGRES_REDIS_INTEGRATION', false)) {
            self::markTestSkipped('Set YAANDU_POSTGRES_REDIS_INTEGRATION=true to run against the dedicated local PostgreSQL/Redis integration environment.');
        }
        self::assertSame('pgsql', DB::connection()->getDriverName(), 'Review-history locking compatibility must be checked on PostgreSQL.');

        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'PostgreSQL review history integration',
            'slug' => 'pg-review-'.Str::lower(Str::random(12)), 'status' => 'active']);
        $user = User::create(['name' => 'Review History Owner', 'email' => 'pg-review-'.Str::lower(Str::random(12)).'@example.test',
            'password' => Hash::make(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        try {
            $company = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Review History Fixture',
                'normalized_domain' => 'pg-review-'.Str::lower(Str::random(12)).'.example.test', 'source' => 'integration_fixture', 'status' => 'new']);
            $cohortId = (string) Str::uuid();
            $batchId = (string) Str::uuid();
            $rowId = (string) Str::uuid();
            DB::table('pilot_cohorts')->insert(['id' => $cohortId, 'tenant_id' => $tenant->id, 'name' => 'Review history integration',
                'owner_user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('prospect_import_batches')->insert(['id' => $batchId, 'tenant_id' => $tenant->id, 'pilot_cohort_id' => $cohortId,
                'created_by' => $user->id, 'file_name' => 'review-history.csv', 'file_sha256' => hash('sha256', $rowId), 'status' => 'completed',
                'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('prospect_import_rows')->insert(['id' => $rowId, 'tenant_id' => $tenant->id, 'batch_id' => $batchId, 'company_id' => $company->id,
                'row_number' => 2, 'encrypted_payload' => Crypt::encryptString('{}'), 'original_name' => $company->name,
                'normalized_domain' => $company->normalized_domain, 'validation_status' => 'valid', 'deduplication_status' => 'new',
                'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

            Sanctum::actingAs($user);
            $payload = ['intelligence_rating' => 'useful', 'intelligence_rubric_score' => 12,
                'lead_score_rating' => 'insufficient_evidence', 'recommendation_rating' => 'unable_to_assess',
                'technology_accuracy_rating' => 'unknown', 'next_action_rating' => 'unavailable', 'unsupported_claim_count' => 0,
                'claim_reviews' => [['claim' => 'The fixture identity is a review-history test.', 'status' => 'supported']]];
            $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/import-rows/'.$rowId.'/review', $payload)
                ->assertOk()->assertJsonPath('review_status', 'reviewed');
            $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/import-rows/'.$rowId.'/review', $payload)
                ->assertOk()->assertJsonPath('review_status', 'reviewed');

            self::assertSame([2, 1], $this->withHeader('X-Tenant-ID', $tenant->id)
                ->getJson('/api/v1/pilot/import-rows/'.$rowId.'/reviews')->assertOk()->json('reviews.*.review_version'));
        } finally {
            $tenant->delete();
            $user->delete();
        }
    }

    private static array $usedRedisPrefixes = [];

    /** Each test's worker and queue connection share a fresh prefix distinct from the live Horizon prefix. */
    private function isolateRedisQueue(): void
    {
        $livePrefix = (string) config('database.redis.options.prefix');
        $testPrefix = 'yaandu_s7_it_'.Str::lower(Str::random(24)).'_';
        self::assertNotSame($livePrefix, $testPrefix, 'Integration workers must not use the live Horizon Redis prefix.');
        self::assertNotContains($testPrefix, self::$usedRedisPrefixes, 'Each integration test must use a fresh Redis prefix.');
        self::$usedRedisPrefixes[] = $testPrefix;
        config(['database.redis.options.prefix' => $testPrefix]);
        // RedisManager captures its configuration at construction. Changing Laravel's
        // config array and purging a connection alone leaves the original prefix in use.
        app()->instance('redis', new RedisManager(app(), (string) config('database.redis.client'), config('database.redis')));
        Redis::clearResolvedInstance('redis');
    }

    private function assertQueuedJob(string $queue, string $jobClass, array $properties): void
    {
        $this->assertTestRedisPrefix();
        $jobs = $this->queuePayloads($queue);
        $matches = array_filter($jobs, fn (array $payload): bool => $this->matchesJob($payload, $jobClass, $properties));
        self::assertCount(1, $matches, 'Expected one test-owned serialized job on '.$queue.'. Jobs: '.json_encode($this->queueSummary($jobs)));
    }

    private function assertNoQueuedJob(string $queue, string $jobClass, array $properties, string $message = ''): void
    {
        $this->assertTestRedisPrefix();
        $jobs = array_filter($this->queuePayloads($queue), fn (array $payload): bool => $this->matchesJob($payload, $jobClass, $properties));
        self::assertCount(0, $jobs, ($message ?: 'No matching test-owned job should remain.').' Jobs: '.json_encode($this->queueSummary(array_values($jobs))));
    }

    private function assertTestRedisPrefix(): void
    {
        $expectedPrefix = (string) config('database.redis.options.prefix');
        $actualPrefix = (string) Redis::connection('default')->client()->getOption(\Redis::OPT_PREFIX);
        self::assertStringStartsWith('yaandu_s7_it_', $expectedPrefix);
        self::assertSame($expectedPrefix, $actualPrefix,
            'The application and in-process queue worker must use the exact isolated Redis prefix, distinct from Horizon’s runtime prefix.');
    }

    private function queuePayloads(string $queue): array
    {
        $redis = Redis::connection('default');
        $payloads = [];
        foreach (['pending' => $redis->lrange('queues:'.$queue, 0, -1),
            'delayed' => $redis->zrange('queues:'.$queue.':delayed', 0, -1),
            'reserved' => $redis->zrange('queues:'.$queue.':reserved', 0, -1)] as $state => $items) {
            foreach ($items as $item) {
                $payload = json_decode($item, true) ?: [];
                $payload['_queue_state'] = $state;
                $payloads[] = $payload;
            }
        }
        return $payloads;
    }

    private function matchesJob(array $payload, string $jobClass, array $properties): bool
    {
        if (($payload['displayName'] ?? null) !== $jobClass) return false;
        $serialized = (string) data_get($payload, 'data.command', '');
        foreach ($properties as $name => $value) {
            $pattern = '/s:\\d+:"'.preg_quote($name, '/').'";s:\\d+:"'.preg_quote((string) $value, '/').'";/';
            if (! preg_match($pattern, $serialized)) return false;
        }
        return true;
    }

    private function queueSummary(array $payloads): array
    {
        return array_map(static fn (array $payload): array => ['state' => $payload['_queue_state'] ?? null,
            'job' => $payload['displayName'] ?? null, 'uuid' => $payload['uuid'] ?? null], $payloads);
    }
}
