<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_activation_requires_ready_sender_and_approved_sequence(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('lifecycle-activation');
        Sanctum::actingAs($owner);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Ready campaign']);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/activate')
            ->assertUnprocessable()->assertJsonPath('message', 'Outbound messaging is not enabled for this tenant.');

        $this->withHeaders($headers)->putJson('/api/v1/messaging-configuration', [
            'provider' => 'fake', 'enabled' => true, 'from_name' => 'Yaandu', 'from_email' => 'sales@yaandu.example',
            'hourly_limit' => 10, 'daily_limit' => 100,
        ])->assertOk()->assertJsonPath('enabled', 1)->assertJsonMissingPath('secret_reference');

        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/activate')
            ->assertUnprocessable()->assertJsonPath('message', 'Campaign steps must be present and ordered without gaps.');
        $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'name' => 'Approved introduction', 'channel' => 'email', 'subject' => 'A note', 'body' => 'Hello.', 'status' => 'approved', 'version' => 1]);
        CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'template_id' => $template->id,
            'ordinal' => 1, 'step_type' => 'email', 'delay_seconds' => 0]);
        Queue::fake();

        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/activate')
            ->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('campaigns', ['tenant_id' => $tenant->id, 'id' => $campaign->id, 'status' => 'active']);
    }

    public function test_messaging_configuration_rejects_unallowlisted_providers_and_is_admin_only(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('provider-allowlist');
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->putJson('/api/v1/messaging-configuration', [
            'provider' => 'smtp-vendor', 'enabled' => true, 'from_email' => 'sales@yaandu.example', 'hourly_limit' => 10, 'daily_limit' => 100,
        ])->assertUnprocessable();

        $member = User::create(['name' => 'Member', 'email' => 'member-provider@test.local', 'password' => 'hashed-test-password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $this->withHeaders($headers)->putJson('/api/v1/messaging-configuration', [
            'provider' => 'fake', 'enabled' => false, 'hourly_limit' => 10, 'daily_limit' => 100,
        ])->assertForbidden();
    }

    private function tenantAndOwner(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $owner = User::create(['name' => 'Owner', 'email' => $slug.'@test.local', 'password' => 'hashed-test-password']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);

        return [$tenant, $owner];
    }
}
