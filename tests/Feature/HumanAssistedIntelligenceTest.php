<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class HumanAssistedIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_service_and_priority_decisions_are_tenant_scoped_and_append_only(): void
    {
        [$tenant, $user, $company, $row] = $this->prospect('human-decision');
        $serviceId = (string) Str::uuid();
        DB::table('tenant_services')->insert(['id' => $serviceId, 'tenant_id' => $tenant->id, 'sku' => 'web-modernization',
            'name' => 'Website Modernization', 'unit_price' => null, 'currency' => 'INR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $path = '/api/v1/pilot/import-rows/'.$row.'/human-decision';
        $headers = ['X-Tenant-ID' => $tenant->id];
        $payload = ['service_decision' => 'selected', 'selected_service_ids' => [$serviceId], 'priority' => 'high', 'notes' => 'Evidence supports a human-selected service.'];
        $first = $this->withHeaders($headers)->postJson($path, $payload)->assertCreated()->json();
        $second = $this->withHeaders($headers)->postJson($path, [...$payload, 'priority' => 'medium'])->assertCreated()->json();
        self::assertSame($user->id, $first['reviewer_id']);
        self::assertSame($row, $first['prospect_import_row_id']);
        self::assertSame([$serviceId], json_decode($first['selected_service_ids'], true));
        self::assertDatabaseCount('pilot_human_decisions', 2);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'pilot_human_decision_saved', 'subject_id' => $second['id']]);
        self::assertSame('medium', $second['priority']);
        $this->withHeaders($headers)->getJson('/api/v1/pilot/import-rows/'.$row.'/human-decisions')->assertOk()
            ->assertJsonPath('latest_decision.id', $second['id'])->assertJsonCount(2, 'decisions');
        $this->withHeaders($headers)->getJson('/api/v1/pilot/sales-intelligence-mode')->assertOk()
            ->assertJsonPath('sales_intelligence_mode', 'human_assisted')->assertJsonPath('ai_score_is_advisory', true);
        $companyData = $this->withHeaders($headers)->getJson('/api/v1/companies/'.$company->id)->assertOk()->json();
        self::assertSame([$serviceId], $companyData['import_provenance']['human_decision']['selected_service_ids']);
    }

    public function test_inactive_or_cross_tenant_service_cannot_be_selected_and_cross_tenant_row_is_hidden(): void
    {
        [$tenant, $user, , $row] = $this->prospect('human-decision-isolation');
        [$other, , , ] = $this->prospect('human-decision-other');
        $foreignService = (string) Str::uuid();
        DB::table('tenant_services')->insert(['id' => $foreignService, 'tenant_id' => $other->id, 'sku' => 'foreign', 'name' => 'Foreign service',
            'unit_price' => null, 'currency' => 'INR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $payload = ['service_decision' => 'selected', 'selected_service_ids' => [$foreignService], 'priority' => 'high'];
        $this->withHeaders($headers)->postJson('/api/v1/pilot/import-rows/'.$row.'/human-decision', $payload)->assertUnprocessable();
        $this->withHeaders(['X-Tenant-ID' => $other->id])->postJson('/api/v1/pilot/import-rows/'.$row.'/human-decision', [
            'service_decision' => 'no_service', 'selected_service_ids' => [], 'priority' => 'not_a_fit',
        ])->assertForbidden();
        $this->assertDatabaseCount('pilot_human_decisions', 0);
    }

    private function prospect(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => 'Sales Owner', 'email' => $slug.'@example.test', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => $slug, 'normalized_domain' => $slug.'.example', 'status' => 'new']);
        $batch = (string) Str::uuid();
        DB::table('prospect_import_batches')->insert(['id' => $batch, 'tenant_id' => $tenant->id, 'created_by' => $user->id,
            'file_name' => 'fixture.csv', 'file_sha256' => hash('sha256', $slug), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $row = (string) Str::uuid();
        DB::table('prospect_import_rows')->insert(['id' => $row, 'tenant_id' => $tenant->id, 'batch_id' => $batch, 'company_id' => $company->id,
            'row_number' => 1, 'encrypted_payload' => 'encrypted-fixture', 'original_name' => $slug, 'validation_status' => 'valid',
            'deduplication_status' => 'new', 'status' => 'completed', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return [$tenant, $user, $company, $row];
    }
}
