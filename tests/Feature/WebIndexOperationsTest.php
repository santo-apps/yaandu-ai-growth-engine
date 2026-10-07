<?php

namespace Tests\Feature;

use App\Jobs\RunWebIndexIngestionJob;
use App\Models\Tenant;
use App\Models\User;
use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebIndexOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_manager_can_view_shared_public_corpus_and_queue_bounded_seed_run(): void
    {
        [$tenant, $owner] = $this->tenantUser();
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner); Queue::fake();

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations/web-index')->assertOk()
            ->assertJsonPath('corpus.documents', 0)->assertJsonPath('corpus.domains', 0)
            ->assertJsonPath('quality.quality_domains', 0)
            ->assertJsonPath('quality.classification.HIGH_QUALITY_BUSINESS', 0);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/operations/web-index/runs', [
            'source' => 'osm_public_websites', 'limit' => 25, 'location' => 'Chennai', 'categories' => ['healthcare'], 'max_bytes' => 2_000_000,
        ])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('queue', 'web-index');

        $this->assertDatabaseHas('web_index_ingestion_runs', ['id' => $response->json('run_id'), 'status' => 'pending', 'source' => 'osm_public_websites']);
        Queue::assertPushedOn('web-index', RunWebIndexIngestionJob::class);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'web_index_ingestion_queued', 'subject_id' => $response->json('run_id')]);
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('workflow_approvals')->where('tenant_id', $tenant->id)->where('action', 'SEND_OUTREACH')->count());
    }

    public function test_sales_role_and_nonmember_cannot_access_global_corpus_operations(): void
    {
        [$tenant, $sales] = $this->tenantUser();
        $sales->tenants()->attach($tenant->id, ['role' => 'salesperson', 'status' => 'active']);
        Sanctum::actingAs($sales);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations/web-index')->assertForbidden();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/operations/web-index/runs', ['source' => 'verified_discovery', 'limit' => 10])->assertForbidden();
        $otherTenant = (string) Str::uuid();
        $this->withHeader('X-Tenant-ID', $otherTenant)->getJson('/api/v1/operations/web-index')->assertForbidden();
    }

    public function test_bounded_ingestion_run_can_be_requeued_on_web_index_queue_from_its_checkpoint(): void
    {
        $source = app(WebIndexIngestionSourceRegistry::class)->get('verified_discovery');
        $runId = app(LocalWebIndexIngestionService::class)->createRun($source, 2, ['max_bytes' => 123456, 'max_runtime_seconds' => 90]);
        $cursor = ['offset' => 1];
        DB::table('web_index_ingestion_runs')->where('id', $runId)->update(['status' => 'partially_completed', 'processed' => 1,
            'cursor' => json_encode($cursor), 'checkpointed_at' => now()]);
        Queue::fake();

        $this->artisan('web-index:ingest', ['--source' => 'verified_discovery', '--limit' => 3, '--resume' => $runId, '--queue' => true])
            ->expectsOutputToContain('Requeued resumable web-index run '.$runId)->assertExitCode(0);

        $run = DB::table('web_index_ingestion_runs')->where('id', $runId)->first();
        self::assertSame('pending', $run->status);
        self::assertSame(3, (int) $run->requested_limit);
        self::assertSame($cursor, json_decode((string) $run->cursor, true));
        self::assertSame(123456, (int) json_decode((string) $run->options, true)['max_bytes']);
        self::assertSame(90, (int) json_decode((string) $run->options, true)['max_runtime_seconds']);
        Queue::assertPushedOn('web-index', RunWebIndexIngestionJob::class, fn ($job) => $job->runId === $runId);
    }

    private function tenantUser(): array
    {
        $suffix = Str::uuid()->toString();
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Corpus '.$suffix, 'slug' => 'corpus-'.substr($suffix, 0, 8), 'status' => 'active']);
        $user = User::create(['name' => 'Corpus operator', 'email' => 'corpus-'.substr($suffix, 0, 8).'@example.test', 'password' => bcrypt('test-password')]);
        return [$tenant, $user];
    }
}
