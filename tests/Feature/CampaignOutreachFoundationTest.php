<?php

namespace Tests\Feature;

use App\Contacts\ContactMethodValue;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Orchestration\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignOutreachFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_lifecycle_cancel_records_event_and_audit_and_rejects_invalid_start(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('cancel-campaign');
        Sanctum::actingAs($owner);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Cancelable']);
        $workflow = app(WorkflowService::class)->create($tenant->id, ['campaign_id' => $campaign->id]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/campaigns/'.$campaign->id.'/cancel')
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/campaigns/'.$campaign->id.'/start')
            ->assertUnprocessable();
        $this->assertDatabaseHas('campaign_events', ['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'event_type' => 'campaign_cancelled']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'actor_user_id' => $owner->id, 'action' => 'campaign_cancelled']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'id' => $workflow->id, 'status' => 'CANCELLED']);
    }

    public function test_sequence_steps_can_be_listed_updated_reordered_and_deleted_without_duplicate_ordinals(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('sequence-admin');
        Sanctum::actingAs($owner);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Editable sequence']);
        $steps = [];
        foreach ([1, 2, 3] as $ordinal) {
            $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
                'name' => 'Email '.$ordinal, 'channel' => 'email', 'subject' => 'Subject '.$ordinal, 'body' => 'Body', 'status' => 'approved', 'version' => 1]);
            $steps[] = CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
                'template_id' => $template->id, 'ordinal' => $ordinal, 'step_type' => 'email', 'delay_seconds' => 86400]);
        }
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/campaigns/'.$campaign->id.'/steps')->assertOk()->assertJsonCount(3);
        $this->withHeaders($headers)->patchJson('/api/v1/campaigns/'.$campaign->id.'/steps/'.$steps[0]->id,
            ['active' => false, 'delay_seconds' => 172800])->assertOk()->assertJsonPath('active', false);
        $this->withHeaders($headers)->putJson('/api/v1/campaigns/'.$campaign->id.'/steps/reorder',
            ['step_ids' => [$steps[2]->id, $steps[0]->id, $steps[1]->id]])->assertOk();
        self::assertSame([$steps[2]->id, $steps[0]->id, $steps[1]->id], CampaignStep::where('campaign_id', $campaign->id)->orderBy('ordinal')->pluck('id')->all());
        $this->withHeaders($headers)->deleteJson('/api/v1/campaigns/'.$campaign->id.'/steps/'.$steps[0]->id)->assertNoContent();
        self::assertSame([1, 2], CampaignStep::where('campaign_id', $campaign->id)->orderBy('ordinal')->pluck('ordinal')->all());
    }

    public function test_manual_suppression_is_tenant_scoped_and_stops_active_enrollment(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('manual-suppress');
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Suppressed sequence']);
        [$companyId, $contactId, $methodId] = $this->publicContact($tenant, 'lead@manual-suppress.test');
        $enrollment = CampaignEnrollment::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'company_id' => $companyId,
            'contact_id' => $contactId, 'contact_method_id' => $methodId, 'idempotency_key' => 'manual-suppress-enroll',
            'status' => 'active', 'enrolled_at' => now()]);
        Sanctum::actingAs($owner);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/suppressions', ['email' => 'lead@manual-suppress.test'])
            ->assertCreated()->assertJsonMissingPath('email');
        $this->assertDatabaseHas('campaign_recipients', ['tenant_id' => $tenant->id, 'id' => $enrollment->id, 'status' => 'suppressed']);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/suppressions')->assertOk()->assertJsonPath('data.0.reason', 'manual_suppression');

        $other = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'manual-suppress-other']);
        $owner->tenants()->attach($other->id, ['role' => 'owner', 'status' => 'active']);
        $this->withHeader('X-Tenant-ID', $other->id)->getJson('/api/v1/suppressions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_message_read_is_tenant_scoped_and_decrypts_only_for_authorized_tenant(): void
    {
        [$tenant, $owner] = $this->tenantAndOwner('message-read');
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Message campaign']);
        [$companyId, $contactId, $methodId] = $this->publicContact($tenant, 'lead@message-read.test');
        $enrollment = CampaignEnrollment::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'company_id' => $companyId,
            'contact_id' => $contactId, 'contact_method_id' => $methodId, 'idempotency_key' => 'message-read-enroll', 'status' => 'completed']);
        $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'name' => 'One', 'channel' => 'email', 'subject' => 'Subject', 'body' => 'Body', 'status' => 'approved', 'version' => 1]);
        $step = CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'template_id' => $template->id, 'ordinal' => 1, 'step_type' => 'email']);
        $messageId = (string) Str::uuid();
        DB::table('outbound_messages')->insert(['id' => $messageId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $enrollment->id, 'campaign_step_id' => $step->id, 'contact_method_id' => $methodId,
            'idempotency_key' => 'message-read-key', 'provider' => 'fake', 'status' => 'accepted', 'channel' => 'email',
            'subject_ciphertext' => Crypt::encryptString('Encrypted subject'), 'body_ciphertext' => Crypt::encryptString('Encrypted message body'),
            'recipient_hash' => hash('sha256', 'private'), 'attempt_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($owner);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/outbound-messages')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.body_ciphertext');
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/outbound-messages/'.$messageId)
            ->assertOk()->assertJsonPath('subject', 'Encrypted subject')->assertJsonPath('content', 'Encrypted message body');
        $other = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'message-read-other']);
        $owner->tenants()->attach($other->id, ['role' => 'owner', 'status' => 'active']);
        $this->withHeader('X-Tenant-ID', $other->id)->getJson('/api/v1/outbound-messages/'.$messageId)->assertNotFound();
    }

    public function test_database_rejects_enrollment_with_cross_tenant_contact_company_or_method(): void
    {
        [$tenantA] = $this->tenantAndOwner('enrollment-owner-a');
        [$tenantB] = $this->tenantAndOwner('enrollment-owner-b');
        $campaign = Campaign::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A campaign']);
        [$companyId, $contactId, $methodId] = $this->publicContact($tenantB, 'lead@cross-tenant.test');

        try {
            DB::table('campaign_recipients')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantA->id,
                'campaign_id' => $campaign->id, 'company_id' => $companyId, 'contact_id' => $contactId, 'contact_method_id' => $methodId,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            self::fail('The database should reject cross-tenant enrollment relationships.');
        } catch (QueryException) {
            $this->assertDatabaseCount('campaign_recipients', 0);
        }
    }

    public function test_database_rejects_cross_tenant_message_event_and_conversation_links(): void
    {
        [$tenantA] = $this->tenantAndOwner('integrity-a');
        [$tenantB] = $this->tenantAndOwner('integrity-b');
        $campaignA = Campaign::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A']);
        $campaignB = Campaign::create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B']);
        [$companyA, $contactA, $methodA] = $this->publicContact($tenantA, 'lead@integrity-a.test');
        [$companyB, $contactB, $methodB] = $this->publicContact($tenantB, 'lead@integrity-b.test');
        $enrollmentB = CampaignEnrollment::create(['tenant_id' => $tenantB->id, 'campaign_id' => $campaignB->id,
            'company_id' => $companyB, 'contact_id' => $contactB, 'contact_method_id' => $methodB,
            'idempotency_key' => 'integrity-b-enrollment', 'status' => 'active']);
        $templateA = CampaignTemplate::create(['tenant_id' => $tenantA->id, 'campaign_id' => $campaignA->id,
            'name' => 'A', 'channel' => 'email', 'subject' => 'Subject', 'body' => 'Body', 'status' => 'approved', 'version' => 1]);
        $stepA = CampaignStep::create(['tenant_id' => $tenantA->id, 'campaign_id' => $campaignA->id,
            'template_id' => $templateA->id, 'ordinal' => 1, 'step_type' => 'email']);

        try {
            DB::table('outbound_messages')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantA->id,
                'campaign_id' => $campaignA->id, 'campaign_recipient_id' => $enrollmentB->id, 'campaign_step_id' => $stepA->id,
                'contact_method_id' => $methodA, 'idempotency_key' => 'cross-tenant-message', 'provider' => 'fake', 'status' => 'queued',
                'channel' => 'email', 'subject_ciphertext' => Crypt::encryptString('Subject'), 'body_ciphertext' => Crypt::encryptString('Body'),
                'recipient_hash' => hash('sha256', 'test'), 'created_at' => now(), 'updated_at' => now()]);
            self::fail('A message must not reference another tenant enrollment.');
        } catch (QueryException) { $this->assertDatabaseCount('outbound_messages', 0); }

        try {
            DB::table('campaign_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantA->id,
                'campaign_id' => $campaignA->id, 'campaign_recipient_id' => $enrollmentB->id, 'event_type' => 'contact_replied',
                'idempotency_key' => 'cross-tenant-event', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            self::fail('A campaign event must not reference another tenant enrollment.');
        } catch (QueryException) { $this->assertDatabaseCount('campaign_events', 0); }

        try {
            DB::table('conversations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantA->id,
                'company_id' => $companyA, 'contact_id' => $contactA, 'campaign_id' => $campaignA->id,
                'campaign_recipient_id' => $enrollmentB->id, 'channel' => 'email', 'status' => 'ai_active',
                'created_at' => now(), 'updated_at' => now()]);
            self::fail('A conversation must not reference another tenant enrollment.');
        } catch (QueryException) { $this->assertDatabaseCount('conversations', 0); }
    }

    private function tenantAndOwner(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $owner = User::create(['name' => 'Owner', 'email' => $slug.'@test.local', 'password' => 'hashed-test-password']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);

        return [$tenant, $owner];
    }

    private function publicContact(Tenant $tenant, string $email): array
    {
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Public Company', 'normalized_domain' => 'example.test',
            'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'name' => 'Public Contact',
            'source_url' => 'https://example.test/team', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $methodId = (string) Str::uuid();
        $values = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => $methodId, 'tenant_id' => $tenant->id, 'contact_id' => $contactId, 'type' => 'email',
            'value' => $values->encrypt($email), 'value_hash' => $values->fingerprint('email', $email), 'source_url' => 'https://example.test/team',
            'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 1, 'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);

        return [$companyId, $contactId, $methodId];
    }
}
