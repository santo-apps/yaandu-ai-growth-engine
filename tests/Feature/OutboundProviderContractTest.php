<?php

namespace Tests\Feature;

use App\Messaging\OutboundMessageRequest;
use App\Messaging\OutboundMessageStatus;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class OutboundProviderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_router_fails_closed_and_fake_provider_is_idempotent(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Messaging Tenant', 'slug' => 'messaging-router']);
        $router = app(OutboundMessagingProviderRouter::class);
        try {
            $router->forTenant($tenant->id);
            self::fail('A tenant without an enabled provider must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Outbound messaging is not enabled for this tenant.', $exception->getMessage());
        }

        DB::table('tenant_messaging_configurations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => 'fake', 'enabled' => true,
            'hourly_limit' => 5, 'daily_limit' => 20, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provider = $router->forTenant($tenant->id);
        $message = new OutboundMessageRequest($tenant->id, 'sales@yaandu.example', 'lead@example.test', 'A short hello', 'Hello.');
        $first = $provider->send($message, 'tenant-campaign-step-1');
        $again = $provider->send($message, 'tenant-campaign-step-1');

        self::assertSame($first->providerMessageId, $again->providerMessageId);
        self::assertSame(OutboundMessageStatus::Accepted, $provider->status($first->providerMessageId));
        self::assertSame(OutboundMessageStatus::Unknown, $provider->status('missing'));
    }

    public function test_fake_provider_verifies_signature_and_normalizes_events(): void
    {
        $provider = new \App\Messaging\FakeOutboundMessagingProvider;
        $raw = '{"event":"delivered"}';
        $signature = hash_hmac('sha256', $raw, 'local-test-secret');
        self::assertTrue($provider->verifyWebhookSignature($raw, $signature, 'local-test-secret'));
        self::assertFalse($provider->verifyWebhookSignature($raw, $signature, 'wrong-secret'));

        $event = $provider->normalizeWebhookEvent(['event_id' => 'evt-1', 'message_id' => 'msg-1', 'status' => 'delivered', 'occurred_at' => '2026-10-04T10:00:00Z']);
        self::assertSame('evt-1', $event->eventId);
        self::assertSame(OutboundMessageStatus::Delivered, $event->status);
        $this->expectException(InvalidArgumentException::class);
        $provider->normalizeWebhookEvent(['event_id' => 'evt-2', 'message_id' => 'msg-2', 'status' => 'sent;ignore', 'occurred_at' => 'today']);
    }

    public function test_email_request_rejects_header_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OutboundMessageRequest('tenant', 'sales@yaandu.example', 'lead@example.test', "Hello\r\nBcc: attacker@example.test", 'Body');
    }
}
