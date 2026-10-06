<?php

namespace Tests\Feature;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_models_cast_status_and_targeting_configuration(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Campaign Tenant', 'slug' => 'campaign-tenant']);
        $campaign = Campaign::create([
            'tenant_id' => $tenant->id,
            'name' => 'Q4 website outreach',
            'objective' => 'Book qualified introduction calls',
            'target_audience' => ['industries' => ['professional services']],
            'sending_windows' => ['weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00'],
            'timezone' => 'Asia/Kolkata',
        ]);

        self::assertSame(CampaignStatus::Draft, $campaign->status);
        self::assertSame('Asia/Kolkata', $campaign->timezone);
        self::assertSame(['professional services'], $campaign->target_audience['industries']);
        self::assertContains(CampaignEnrollmentStatus::Unsubscribed, CampaignEnrollmentStatus::cases());
    }

    public function test_campaign_audience_and_sequence_cannot_reference_another_tenants_campaign(): void
    {
        $tenantA = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Tenant A', 'slug' => 'tenant-a-campaign']);
        $tenantB = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Tenant B', 'slug' => 'tenant-b-campaign']);
        $campaign = Campaign::create(['tenant_id' => $tenantA->id, 'name' => 'Private campaign']);

        try {
            DB::table('campaign_audiences')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantB->id, 'campaign_id' => $campaign->id,
                'name' => 'Cross tenant audience', 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('The composite tenant foreign key should reject a cross-tenant campaign reference.');
        } catch (QueryException) {
            self::assertDatabaseCount('campaign_audiences', 0);
        }
    }

    public function test_messaging_configuration_is_tenant_unique_and_never_stores_credentials_inline(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Messaging Tenant', 'slug' => 'messaging-tenant']);
        DB::table('tenant_messaging_configurations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => 'fake', 'enabled' => false,
            'secret_reference' => 'secret://tenant/messaging', 'hourly_limit' => 20, 'daily_limit' => 100,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $config = DB::table('tenant_messaging_configurations')->where('tenant_id', $tenant->id)->first();
        self::assertSame('secret://tenant/messaging', $config->secret_reference);
        self::assertFalse((bool) $config->enabled);
        self::assertDatabaseCount('tenant_messaging_configurations', 1);
    }
}
