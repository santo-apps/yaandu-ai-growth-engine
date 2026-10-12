<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompanyHumanSalesReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_review_is_tenant_scoped_current_and_has_no_sales_side_effects(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->workspace('sales-review');
        $company = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Review prospect', 'status' => 'new']);
        DB::table('lead_scores')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'score' => 67, 'components' => json_encode(['poor_mobile_ux' => ['status' => 'POSITIVE', 'points' => 10]]), 'rule_version' => 1,
            'evaluation_status' => 'scored', 'evidence_coverage' => 60, 'scored_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $serviceId = (string) Str::uuid();
        DB::table('tenant_services')->insert(['id' => $serviceId, 'tenant_id' => $tenant->id, 'sku' => 'SKU-001', 'name' => 'Website service',
            'unit_price' => 0, 'currency' => 'INR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $path = '/api/v1/companies/'.$company->id.'/sales-review';
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson($path)->assertOk()->assertJsonPath('review', null);
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson($path, [
            'decision' => 'pursue', 'selected_service_id' => $serviceId, 'notes' => 'Human decision, independent of AI priority.',
        ])->assertOk()->assertJsonPath('review.decision', 'pursue')->assertJsonPath('review.service_name', 'Website service')
            ->assertJsonPath('review.reviewer_name', $user->name);
        $this->assertDatabaseCount('company_human_sales_reviews', 1);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'human_sales_review_saved', 'subject_type' => 'company_human_sales_review']);
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'human_sales_review_saved')->first();
        self::assertStringNotContainsString('Human decision, independent', (string) $audit->metadata);

        foreach (['hold', 'reject', 'needs_more_evidence'] as $decision) {
            $this->withHeader('X-Tenant-ID', $tenant->id)->putJson($path, ['decision' => $decision])->assertOk()->assertJsonPath('review.decision', $decision);
        }
        $this->assertDatabaseCount('company_human_sales_reviews', 1);
        self::assertSame(4, DB::table('audit_logs')->where('action', 'human_sales_review_saved')->count());
        self::assertDatabaseHas('lead_scores', ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'score' => 67, 'evidence_coverage' => 60]);
        foreach (['website_scans', 'campaign_recipients', 'outbound_messages', 'sales_opportunities', 'campaign_events'] as $table) {
            self::assertSame(0, DB::table($table)->where('tenant_id', $tenant->id)->count(), "Review must not change {$table}.");
        }
        Queue::assertNothingPushed();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson($path)->assertOk()->assertJsonPath('review.decision', 'needs_more_evidence')
            ->assertJsonPath('review.notes', null);

        [$otherTenant, $otherUser] = $this->workspace('sales-review-other');
        $otherService = (string) Str::uuid();
        DB::table('tenant_services')->insert(['id' => $otherService, 'tenant_id' => $otherTenant->id, 'sku' => 'OTHER-001', 'name' => 'Other service',
            'unit_price' => 0, 'currency' => 'INR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson($path)->assertNotFound();
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->putJson($path, ['decision' => 'reject', 'selected_service_id' => $otherService])->assertNotFound();
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson($path, ['decision' => 'pursue', 'selected_service_id' => $otherService])->assertStatus(422);
    }

    public function test_invalid_decisions_and_inactive_or_foreign_services_are_rejected(): void
    {
        [$tenant, $user] = $this->workspace('sales-review-validation');
        $company = Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Review prospect', 'status' => 'new']);
        $serviceId = (string) Str::uuid();
        DB::table('tenant_services')->insert(['id' => $serviceId, 'tenant_id' => $tenant->id, 'sku' => 'SKU-002', 'name' => 'Inactive service',
            'unit_price' => 0, 'currency' => 'INR', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $path = '/api/v1/companies/'.$company->id.'/sales-review';
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson($path, ['decision' => 'needs review'])->assertUnprocessable();
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson($path, ['decision' => 'pursue', 'selected_service_id' => $serviceId])->assertStatus(422);
        $this->assertDatabaseCount('company_human_sales_reviews', 0);
    }

    private function workspace(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => 'Salesperson', 'email' => $slug.'@example.test', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'salesperson', 'status' => 'active']);
        return [$tenant, $user];
    }
}
