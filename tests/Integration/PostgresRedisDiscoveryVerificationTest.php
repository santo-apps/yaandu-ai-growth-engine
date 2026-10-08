<?php

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\UploadedFile;

/**
 * Opt-in integration test. Run only against the configured, migrated local PostgreSQL
 * database with Redis available and Horizon stopped (the test runs one queue worker per stage).
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
            self::assertSame(1, Queue::connection('redis')->size('candidate-discovery'));
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'candidate-discovery', '--once' => true, '--tries' => 1]));

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
            self::assertSame(0, Queue::connection('redis')->size('candidate-discovery'), 'A completed idempotent replay must not enqueue a duplicate job.');
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
            Sanctum::actingAs($user);
            $search = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/discovery/searches', [
                'name' => 'PostgreSQL queued verification regression', 'source' => 'deterministic_local',
                'locations' => ['UAE'], 'industries' => ['E-commerce / Retail'], 'max_candidates' => 1,
            ])->assertCreated()->json();
            $run = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/discovery/searches/'.$search['id'].'/runs', [
                'idempotency_key' => 'pg-integration-'.Str::uuid(),
            ])->assertAccepted()->json();

            // Exercise Laravel's serialized Redis queue boundary instead of calling the job directly.
            self::assertSame(1, Queue::connection('redis')->size('discovery'), 'One serialized DiscoveryAgent job must be queued.');
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'discovery', '--once' => true, '--tries' => 1]));
            self::assertSame(1, Queue::connection('redis')->size('crawl'), 'DiscoveryAgent must enqueue one serialized verification job.');
            $candidate = DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('discovery_run_id', $run['id'])
                ->where('source_reference', 'fixture:new-high')->first();
            self::assertNotNull($candidate, 'The queued DiscoveryAgent must persist the candidate in PostgreSQL.');
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'crawl', '--once' => true, '--tries' => 1]));
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
            self::assertSame(1, Queue::connection('redis')->size('crawl'), 'Verification must enqueue one pre-promotion intelligence/scoring job.');
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'crawl', '--once' => true, '--tries' => 1]));
            self::assertSame(0, Queue::connection('redis')->size('crawl'), 'The queue must drain after the acceptance workflow job.');
            self::assertSame('reviewable', DB::table('discovery_candidates')->where('tenant_id', $tenant->id)->where('id', $candidate->id)->value('lifecycle_status'));
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
            // This integration suite intentionally stops Horizon and consumes jobs with one-off workers.
            self::assertSame('inactive', $operations['horizon']['status']);
            $cohort = $this->withHeaders($headers)->postJson('/api/v1/pilot/cohorts', ['name' => 'Postgres Redis import integration', 'status' => 'active'])->assertCreated()->json();
            $preview = $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/preview', [
                'csv' => UploadedFile::fake()->createWithContent('s7-integration.csv', "business_name,website,country,source\nPG Integration Business,https://www.pg-import-fixture.test,IN,integration fixture\n"),
                'cohort_id' => $cohort['id'],
            ])->assertCreated()->json();
            $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/'.$preview['id'].'/confirm', ['idempotency_key' => 's7-pg-import-'.$tenant->id])->assertAccepted()->assertJsonPath('queued_rows', 1);
            self::assertSame(1, \Illuminate\Support\Facades\Queue::connection('redis')->size('intake'));
            self::assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'intake', '--once' => true, '--tries' => 1]));

            $company = DB::table('companies')->where('tenant_id', $tenant->id)->where('normalized_domain', 'pg-import-fixture.test')->first();
            self::assertNotNull($company);
            self::assertSame('user_supplied_import', DB::table('company_websites')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->value('source'));
            self::assertSame('unverified', DB::table('company_websites')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->value('verification_status'));
            self::assertSame('completed', DB::table('prospect_import_batches')->where('tenant_id', $tenant->id)->where('id', $preview['id'])->value('status'));
            $this->withHeaders($headers)->postJson('/api/v1/pilot/import-batches/'.$preview['id'].'/confirm', ['idempotency_key' => 's7-pg-import-'.$tenant->id])->assertAccepted()->assertJsonPath('queued_rows', 0);
            self::assertSame(0, \Illuminate\Support\Facades\Queue::connection('redis')->size('intake'));

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

    /** Use a fresh Redis key namespace per test so global queue observations cannot see other local work. */
    private function isolateRedisQueue(): void
    {
        config(['database.redis.options.prefix' => 'yaandu_s7_it_'.Str::lower(Str::random(24)).'_']);
        Redis::purge('default');
    }
}
