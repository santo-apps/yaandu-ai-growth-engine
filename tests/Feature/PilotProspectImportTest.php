<?php

namespace Tests\Feature;

use App\Jobs\ProcessProspectImportRowJob;
use App\Models\Company;
use App\Models\CompanyWebsite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PilotProspectImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_domain_import_validates_deduplicates_queues_and_preserves_provenance(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->tenantAndUser('pilot-import');
        Sanctum::actingAs($user);
        $cohort = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/cohorts', ['name' => 'Internal pilot', 'status' => 'active'])->assertCreated()->json();
        $csv = "business_name,website,country,city,industry,business_email,business_phone,contact_name,source,notes\n".
            "Northwind Health,https://www.northwind.example/contact,IN,Chennai,Healthcare,hello@northwind.example,+91 98765 43210,Sam Buyer,customer referral,Known-domain test\n".
            "Missing Website,,IN,Chennai,Healthcare,,,,customer referral,Invalid row\n".
            "Duplicate Northwind,https://northwind.example,IN,Chennai,Healthcare,,,,customer referral,Duplicate row\n";
        $preview = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/import-batches/preview', [
            'csv' => UploadedFile::fake()->createWithContent('pilot.csv', $csv), 'cohort_id' => $cohort['id'],
        ])->assertCreated()->json();

        self::assertSame(['total' => 3, 'valid' => 1, 'invalid' => 2], $preview['counts']);
        self::assertSame('invalid', $preview['rows'][1]['validation_status']);
        self::assertSame('duplicate_file', $preview['rows'][2]['deduplication_status']);
        $batchId = $preview['id'];
        $batch = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/pilot/import-batches/{$batchId}/confirm", ['idempotency_key' => 'pilot-import-2026-01'])->assertAccepted()->json();
        self::assertSame(1, $batch['queued_rows']);
        Queue::assertPushed(ProcessProspectImportRowJob::class, 1);

        $rowId = DB::table('prospect_import_rows')->where('tenant_id', $tenant->id)->where('batch_id', $batchId)->where('status', 'ready')->value('id');
        $job = new ProcessProspectImportRowJob($tenant->id, $rowId, (string) $user->id);
        $job->handle(app(\App\Contacts\ContactMethodValue::class));
        $job->handle(app(\App\Contacts\ContactMethodValue::class));
        $company = Company::query()->where('tenant_id', $tenant->id)->where('normalized_domain', 'northwind.example')->firstOrFail();
        self::assertSame('unverified', $company->websites()->firstOrFail()->verification_status);
        self::assertSame('user_supplied_import', $company->websites()->firstOrFail()->source);
        self::assertSame(1, DB::table('companies')->where('tenant_id', $tenant->id)->where('normalized_domain', 'northwind.example')->count());
        self::assertSame('user_supplied_import', DB::table('contacts')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->value('extraction_method'));
        $storedEmail = DB::table('contact_methods')->where('tenant_id', $tenant->id)->where('type', 'email')->value('value');
        self::assertNotSame('hello@northwind.example', $storedEmail);
        self::assertSame('hello@northwind.example', app(\App\Contacts\ContactMethodValue::class)->decrypt($storedEmail));
        self::assertSame(app(\App\Contacts\ContactMethodValue::class)->fingerprint('email', 'hello@northwind.example'),
            DB::table('contact_methods')->where('tenant_id', $tenant->id)->where('type', 'email')->value('value_hash'));
        self::assertDatabaseHas('prospect_import_rows', ['tenant_id' => $tenant->id, 'id' => $rowId, 'company_id' => $company->id, 'status' => 'completed']);
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'prospect_imported', 'subject_id' => $company->id]);
        self::assertDatabaseHas('prospect_import_batches', ['tenant_id' => $tenant->id, 'id' => $batchId, 'status' => 'partially_completed']);
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
        $companyView = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/companies/'.$company->id)->assertOk()->json();
        self::assertSame($batchId, $companyView['import_provenance']['batch_id']);
        self::assertSame('customer referral', $companyView['import_provenance']['source']);
        self::assertSame('Pilot Owner', $companyView['import_provenance']['imported_by']);
        self::assertSame('completed', $companyView['import_provenance']['import_status']);
        $dashboard = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/dashboard?cohort_id='.$cohort['id'])->assertOk()->json();
        self::assertSame(1, $dashboard['metrics']['prospects_imported']);
        self::assertSame(1, $dashboard['metrics']['valid_prospects']);
        self::assertSame(1, $dashboard['metrics']['duplicates']);
        self::assertSame(0, $dashboard['funnel']['analyzed']);
        self::assertSame(0, $dashboard['metrics']['sandbox_messages_sent']);
        self::assertNull($dashboard['rates_percent']['website_analysis_completion_rate']);

        $opportunityId = (string) Str::uuid();
        DB::table('sales_opportunities')->insert(['id' => $opportunityId, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'stage' => 'NEW', 'status' => 'open', 'qualification_score' => 40, 'qualification_level' => 'DEVELOPING',
            'qualification' => json_encode(['score' => 40]), 'created_at' => now(), 'updated_at' => now()]);
        $dashboard = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/dashboard?cohort_id='.$cohort['id'])->assertOk()->json();
        self::assertSame(0, $dashboard['metrics']['qualified_leads'], 'A qualification snapshot below threshold is not a qualified lead.');
        self::assertSame(0, $dashboard['funnel']['qualified']);
        self::assertSame(1, $dashboard['metrics']['opportunities'], 'The unqualified opportunity remains visible as an opportunity.');

        DB::table('sales_opportunities')->where('tenant_id', $tenant->id)->where('id', $opportunityId)->update(['qualified_at' => now()]);
        $dashboard = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/dashboard?cohort_id='.$cohort['id'])->assertOk()->json();
        self::assertSame(1, $dashboard['metrics']['qualified_leads']);
        self::assertSame(1, $dashboard['funnel']['qualified']);

        // Unqualified opportunities can exist before qualification; conversion is measured from replied prospects,
        // so this cannot exceed 100% merely because an older opportunity remains open.
        $secondCompany = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Second Pilot Prospect',
            'normalized_domain' => 'second-pilot.example', 'source' => 'user_supplied_import', 'status' => 'new']);
        DB::table('prospect_import_rows')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'batch_id' => $batchId,
            'company_id' => $secondCompany->id, 'row_number' => 99, 'encrypted_payload' => 'fixture-payload', 'original_name' => $secondCompany->name,
            'original_website' => 'https://second-pilot.example', 'normalized_domain' => 'second-pilot.example', 'source' => 'manual fixture',
            'validation_status' => 'valid', 'deduplication_status' => 'new', 'status' => 'completed', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_opportunities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'company_id' => $secondCompany->id,
            'stage' => 'NEW', 'status' => 'open', 'qualification_score' => 40, 'qualification_level' => 'DEVELOPING',
            'qualification' => json_encode(['score' => 40]), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$company, $secondCompany] as $repliedCompany) {
            $conversationId = (string) Str::uuid();
            DB::table('conversations')->insert(['id' => $conversationId, 'tenant_id' => $tenant->id, 'company_id' => $repliedCompany->id,
                'channel' => 'email', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversation_messages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'conversation_id' => $conversationId,
                'direction' => 'inbound', 'body' => 'Fictional pilot reply.', 'created_at' => now(), 'updated_at' => now()]);
        }
        $dashboard = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/dashboard?cohort_id='.$cohort['id'])->assertOk()->json();
        self::assertSame(2, $dashboard['metrics']['opportunities']);
        self::assertSame(2, $dashboard['funnel']['replied']);
        self::assertSame(100, $dashboard['rates_percent']['opportunity_conversion']);
    }

    public function test_import_batch_and_dashboard_are_tenant_scoped_and_unsafe_urls_are_rejected(): void
    {
        [$tenant, $user] = $this->tenantAndUser('pilot-import-owner');
        Sanctum::actingAs($user);
        $preview = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/import-batches/preview', [
            'csv' => UploadedFile::fake()->createWithContent('unsafe.csv', "business_name,website,country,source\nUnsafe,http://127.0.0.1/admin,IN,public directory\n"),
        ])->assertCreated()->json();
        self::assertSame(0, $preview['counts']['valid']);
        self::assertStringContainsString('Private and reserved', implode(' ', $preview['rows'][0]['errors']));

        $other = $this->tenantAndUser('pilot-import-other');
        Sanctum::actingAs($other[1]);
        $this->withHeader('X-Tenant-ID', $other[0]->id)->getJson('/api/v1/pilot/import-batches/'.$preview['id'])->assertNotFound();
        $dashboard = $this->withHeader('X-Tenant-ID', $other[0]->id)->getJson('/api/v1/pilot/dashboard')->assertOk()->json();
        self::assertSame(0, $dashboard['metrics']['prospects_imported']);
        self::assertTrue($dashboard['simulated']);
    }

    public function test_replayed_confirmation_does_not_enqueue_rows_twice(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->tenantAndUser('pilot-import-replay');
        Sanctum::actingAs($user);
        $preview = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/pilot/import-batches/preview', [
            'csv' => UploadedFile::fake()->createWithContent('one.csv', "business_name,website,country,source\nOne,one.example,AE,manual seed\n"),
        ])->assertCreated()->json();
        $path = '/api/v1/pilot/import-batches/'.$preview['id'].'/confirm';
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson($path, ['idempotency_key' => 'pilot-import-replay-01'])->assertAccepted()->assertJsonPath('queued_rows', 1);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson($path, ['idempotency_key' => 'pilot-import-replay-01'])->assertAccepted()->assertJsonPath('queued_rows', 0);
        Queue::assertPushed(ProcessProspectImportRowJob::class, 1);
    }

    public function test_operational_dashboard_is_manager_only_tenant_scoped_and_redacts_provider_details(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('pilot-operations-owner');
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/operations')->assertOk()
            ->assertJsonPath('infrastructure_scope', 'installation')
            ->assertJsonPath('tenant_metrics.failed_import_rows', 0)
            ->assertJsonPath('tenant_metrics.website_crawl_failures', 0)
            ->assertJsonPath('safety.credentials_exposed', false)
            ->assertJsonPath('safety.provider_payloads_exposed', false)
            ->assertJsonMissingPath('failed_job_payloads');

        $member = User::create(['name' => 'Pilot Member', 'email' => 'pilot-operations-member@example.test', 'password' => 'hashed-test-password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/operations')->assertForbidden();
    }

    public function test_simulated_fixture_website_scan_uses_no_network_and_marks_synthetic_evidence(): void
    {
        [$tenant] = $this->tenantAndUser('pilot-crawler-fixture');
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'SIMULATED · Fixture Business', 'normalized_domain' => 'pilot.fixture.test',
            'source' => 'sprint7_simulated_fixture', 'status' => 'new']);
        $website = CompanyWebsite::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'url' => 'https://pilot.fixture.test',
            'host' => 'pilot.fixture.test', 'verification_status' => 'unverified', 'source' => 'user_supplied_import']);
        $scanId = (string) Str::uuid();
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $website->id,
            'status' => 'queued', 'max_depth' => 1, 'max_pages' => 5, 'created_at' => now(), 'updated_at' => now()]);
        config(['pilot.allow_simulated_fixtures' => true]);
        Storage::fake(config('filesystems.default'));
        Http::preventStrayRequests();

        self::assertSame($scanId, app(\App\Crawling\CrawlerService::class)->crawl($tenant->id, $website->id, existingScanId: $scanId));
        $scan = DB::table('website_scans')->where('tenant_id', $tenant->id)->where('id', $scanId)->first();
        self::assertSame('completed', $scan->status);
        self::assertSame('pilot-simulated-fixture-v1', $scan->crawler_version);
        self::assertTrue((bool) json_decode($scan->policy_snapshot)->simulated_fixture);
        $page = DB::table('website_pages')->where('tenant_id', $tenant->id)->where('website_scan_id', $scanId)->first();
        self::assertStringContainsString('SIMULATED ONLY', $page->title);
        self::assertStringContainsString('synthetic', $page->extracted_text);
    }

    private function tenantAndUser(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => 'Pilot Owner', 'email' => $slug.'@example.test', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        return [$tenant, $user];
    }
}
