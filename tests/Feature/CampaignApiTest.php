<?php

namespace Tests\Feature;

use App\Contacts\ContactMethodValue;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_create_and_read_are_tenant_scoped_and_writes_require_admin(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('campaign-api-a', 'owner');
        [, $member] = $this->tenantAndUser('campaign-api-member', 'member');
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($owner);
        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/campaigns', [
            'name' => 'Regional website outreach', 'description' => 'Public site refresh outreach',
            'objective' => 'Book introductory calls', 'timezone' => 'Asia/Kolkata',
            'target_audience' => ['industries' => ['consulting'], 'min_score' => 60],
            'sending_windows' => ['weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00'],
            'rate_limit_per_hour' => 20,
        ]);
        $response->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('timezone', 'Asia/Kolkata')
            ->assertJsonPath('description', 'Public site refresh outreach');
        $campaignId = $response->json('id');
        $this->assertDatabaseHas('campaign_audiences', ['tenant_id' => $tenant->id, 'campaign_id' => $campaignId]);

        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/campaigns/'.$campaignId)->assertOk();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/campaigns', ['name' => 'Forbidden'])->assertForbidden();

        $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'campaign-other-tenant']);
        $owner->tenants()->attach($otherTenant->id, ['role' => 'owner', 'status' => 'active']);
        $this->assertNotSame($otherTenant->id, $tenant->id);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/campaigns/'.$campaignId)->assertNotFound();
    }

    public function test_approved_template_can_be_attached_to_campaign_sequence(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('campaign-sequence', 'owner');
        Sanctum::actingAs($owner);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Sequence test']);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $template = $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/templates', [
            'name' => 'Initial introduction', 'subject' => 'A note for your team', 'body' => "Hello there,\nI noticed your public website.",
        ])->assertCreated()->assertJsonPath('status', 'draft');
        $templateId = $template->json('id');

        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/steps', [
            'ordinal' => 1, 'template_id' => $templateId, 'delay_seconds' => 0,
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/templates/'.$templateId.'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->withHeaders($headers)->postJson('/api/v1/campaigns/'.$campaign->id.'/steps', [
            'ordinal' => 1, 'template_id' => $templateId, 'delay_seconds' => 0,
        ])->assertCreated()->assertJsonPath('ordinal', 1)->assertJsonPath('template.id', $templateId);
    }

    public function test_contact_enrollment_is_idempotent_and_respects_suppression(): void
    {
        [$tenant, $owner] = $this->tenantAndUser('campaign-enrollment', 'owner');
        Sanctum::actingAs($owner);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Enrollment test']);
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Known company',
            'normalized_domain' => 'known.example', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $companyId,
            'source_url' => 'https://known.example/contact', 'observed_at' => now(), 'extraction_method' => 'test_fixture',
            'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $methodId = (string) Str::uuid();
        $values = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => $methodId, 'tenant_id' => $tenant->id, 'contact_id' => $contactId,
            'type' => 'email', 'value' => $values->encrypt('hello@known.example'), 'value_hash' => $values->fingerprint('email', 'hello@known.example'),
            'source_url' => 'https://known.example/contact', 'observed_at' => now(), 'extraction_method' => 'test_fixture',
            'confidence' => 1, 'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'campaign-contact-once'];
        $path = '/api/v1/campaigns/'.$campaign->id.'/enrollments';

        $first = $this->withHeaders($headers)->postJson($path, ['contact_id' => $contactId, 'contact_method_id' => $methodId])->assertCreated();
        $this->withHeaders($headers)->postJson($path, ['contact_id' => $contactId, 'contact_method_id' => $methodId])
            ->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('campaign_recipients', 1);

        DB::table('suppression_lists')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'identifier_hash' => $values->fingerprint('email', 'hello@known.example'), 'identifier_type' => 'email',
            'reason' => 'unsubscribe', 'scope' => 'tenant', 'source' => 'test', 'suppressed_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $otherCampaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Suppression test']);
        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'suppression-second-campaign'])->postJson('/api/v1/campaigns/'.$otherCampaign->id.'/enrollments', [
            'contact_id' => $contactId, 'contact_method_id' => $methodId,
        ])->assertCreated()->assertJsonPath('status', 'suppressed');
    }

    private function tenantAndUser(string $slug, string $role): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => $slug.' user', 'email' => $slug.'@test.local', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => $role, 'status' => 'active']);

        return [$tenant, $user];
    }
}
