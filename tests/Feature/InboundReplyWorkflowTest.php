<?php

namespace Tests\Feature;

use App\Contacts\ContactMethodValue;
use App\Messaging\FakeInboundMessagingProvider;
use App\Messaging\TenantWebhookSecretResolver;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InboundReplyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_inbound_reply_is_encrypted_replay_safe_and_stops_campaign_steps(): void
    {
        Queue::fake();
        [$tenant, $enrollment, $methodId] = $this->fixture('inbound-reply');
        $payload = json_encode(['tenant_id' => $tenant->id, 'event_id' => 'inbound-event-one',
            'sender_email' => 'buyer@inbound-reply.test', 'body' => 'Please stop the follow-up sequence.',
            'occurred_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        $secret = app(TenantWebhookSecretResolver::class)->forTenant($tenant->id, 'fake');
        $provider = new FakeInboundMessagingProvider;
        $signature = $provider->sign($payload, $secret);

        $this->call('POST', '/api/v1/webhooks/inbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => 'invalid',
        ], $payload)->assertForbidden();
        $this->call('POST', '/api/v1/webhooks/inbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();
        $this->call('POST', '/api/v1/webhooks/inbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();

        $this->assertDatabaseHas('campaign_recipients', ['tenant_id' => $tenant->id, 'id' => $enrollment, 'status' => 'replied', 'stop_reason' => 'recipient_replied']);
        self::assertSame(1, DB::table('inbound_message_events')->where('tenant_id', $tenant->id)->count());
        self::assertDatabaseHas('campaign_events', ['tenant_id' => $tenant->id, 'event_type' => 'contact_replied']);
        Queue::assertPushed(\App\Jobs\ProcessFollowUpReply::class, 1);
        self::assertSame(1, DB::table('conversation_messages')->where('tenant_id', $tenant->id)->where('direction', 'inbound')->count());
        $message = DB::table('conversation_messages')->where('tenant_id', $tenant->id)->where('direction', 'inbound')->first();
        self::assertSame('', $message->body);
        self::assertSame('Please stop the follow-up sequence.', Crypt::decryptString($message->body_ciphertext));
        self::assertSame('buyer@inbound-reply.test', app(ContactMethodValue::class)->decrypt(DB::table('contact_methods')->where('id', $methodId)->value('value')));
    }

    private function fixture(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Inbound Company',
            'normalized_domain' => $slug.'.test', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $companyId,
            'name' => 'Buyer', 'source_url' => 'https://'.$slug.'.test/team', 'observed_at' => now(), 'extraction_method' => 'test',
            'confidence' => 0.9, 'created_at' => now(), 'updated_at' => now()]);
        $methodId = (string) Str::uuid();
        $values = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => $methodId, 'tenant_id' => $tenant->id, 'contact_id' => $contactId,
            'type' => 'email', 'value' => $values->encrypt('buyer@'.$slug.'.test'), 'value_hash' => $values->fingerprint('email', 'buyer@'.$slug.'.test'),
            'source_url' => 'https://'.$slug.'.test/team', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 0.9,
            'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        $campaignId = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $campaignId, 'tenant_id' => $tenant->id, 'name' => 'Reply stop campaign',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $enrollmentId = (string) Str::uuid();
        DB::table('campaign_recipients')->insert(['id' => $enrollmentId, 'tenant_id' => $tenant->id, 'campaign_id' => $campaignId,
            'company_id' => $companyId, 'contact_id' => $contactId, 'contact_method_id' => $methodId,
            'idempotency_key' => hash('sha256', $slug), 'status' => 'active', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_messaging_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'provider' => 'fake', 'enabled' => true, 'from_name' => 'Yaandu', 'from_email' => 'sales@yaandu.example',
            'hourly_limit' => 10, 'daily_limit' => 100, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenant, $enrollmentId, $methodId];
    }
}
