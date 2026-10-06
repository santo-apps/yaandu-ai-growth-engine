<?php

namespace App\Http\Controllers\Api\V1;

use App\Acceptance\LocalAcceptanceFixture;
use App\Contacts\ContactMethodValue;
use App\Http\Controllers\Controller;
use App\Messaging\FakeInboundMessagingProvider;
use App\Messaging\InboundMessageProcessor;
use App\Messaging\InboundMessagingProviderRouter;
use App\Messaging\MessageEventProcessor;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Messaging\TenantWebhookSecretResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LocalAcceptanceController extends Controller
{
    public function status(LocalAcceptanceFixture $fixture)
    {
        $tenantId = app('tenant.id');
        $this->authorizeAcceptance($fixture, $tenantId);
        $message = DB::table('outbound_messages')->where('tenant_id', $tenantId)->orderByDesc('created_at')->first(['id', 'status', 'provider']);
        $conversation = DB::table('conversations')->where('tenant_id', $tenantId)->orderByDesc('updated_at')->first(['id']);

        return response()->json(['mode' => 'LOCAL ACCEPTANCE', 'providers' => ['ai' => 'DeterministicAIProvider', 'outbound' => 'FakeOutboundMessagingProvider',
            'scheduling' => 'FakeSchedulingProvider'], 'queue' => config('queue.default'), 'company_id' => DB::table('companies')->where('tenant_id', $tenantId)->value('id'),
            'campaign_id' => DB::table('campaigns')->where('tenant_id', $tenantId)->value('id'), 'campaign_status' => DB::table('campaigns')->where('tenant_id', $tenantId)->value('status'),
            'message' => $message, 'inbound_count' => DB::table('conversation_messages')->where('tenant_id', $tenantId)->where('direction', 'inbound')->count(),
            'conversation_id' => $conversation?->id, 'opportunity_id' => DB::table('sales_opportunities')->where('tenant_id', $tenantId)->value('id'),
            'meeting_id' => DB::table('meeting_bookings')->where('tenant_id', $tenantId)->value('id'), 'proposal_id' => DB::table('proposals')->where('tenant_id', $tenantId)->value('id'),
            'proposal_delivery_count' => DB::table('proposal_deliveries')->where('tenant_id', $tenantId)->count()]);
    }

    /** Simulates the provider's signed status webhook using the ordinary webhook boundary. */
    public function markSent(string $message, LocalAcceptanceFixture $fixture, OutboundMessagingProviderRouter $providers,
        TenantWebhookSecretResolver $secrets, MessageEventProcessor $events)
    {
        $tenantId = app('tenant.id');
        $this->authorizeAcceptance($fixture, $tenantId);
        $row = DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('id', $message)->first();
        abort_unless($row && $row->provider === 'fake', 404);
        abort_unless($row->status === 'accepted' && $row->provider_message_id, 409, 'Only a fake-provider accepted message can be confirmed as sent.');
        $payload = ['tenant_id' => $tenantId, 'event_id' => 'local-acceptance-sent-'.$row->id,
            'message_id' => $row->provider_message_id, 'status' => 'sent', 'occurred_at' => now()->toIso8601String()];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $provider = $providers->forTenant($tenantId);
        $request = Request::create('/api/v1/webhooks/outbound/fake', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => hash_hmac('sha256', $body, $secrets->forTenant($tenantId, 'fake')),
        ], $body);
        $response = app(OutboundWebhookController::class)($request, 'fake', $providers, $secrets, $events);
        abort_unless($response->getStatusCode() === 204, $response->getStatusCode(), 'The fake provider status event was rejected.');
        return $this->status($fixture);
    }

    /** Simulates a reply from the fictional external prospect through the normal inbound webhook processor. */
    public function simulateReply(Request $request, LocalAcceptanceFixture $fixture, InboundMessagingProviderRouter $providers,
        TenantWebhookSecretResolver $secrets, InboundMessageProcessor $processor, ContactMethodValue $values)
    {
        $tenantId = app('tenant.id');
        $this->authorizeAcceptance($fixture, $tenantId);
        $data = $request->validate(['intent' => ['required', 'in:interested,unsubscribe']]);
        abort_unless(DB::table('outbound_messages')->where('tenant_id', $tenantId)->where('provider', 'fake')->where('status', 'sent')->exists(),
            409, 'A fake outbound message must be marked sent before simulating a prospect reply.');
        $contactMethod = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('type', 'email')->first();
        abort_unless($contactMethod, 404);
        $contactEmail = $values->decrypt($contactMethod->value);
        $bodyText = $data['intent'] === 'unsubscribe'
            ? 'UNSUBSCRIBE'
            : 'We are interested in discussing ecommerce modernization and improving our mobile experience. Can we book a meeting next week and receive a proposal? Our budget is still being reviewed.';
        $payload = ['tenant_id' => $tenantId, 'event_id' => 'local-acceptance-reply-v1', 'sender_email' => $contactEmail,
            'body' => $bodyText, 'occurred_at' => now()->toIso8601String()];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $provider = $providers->forTenant($tenantId, 'fake');
        abort_unless($provider instanceof FakeInboundMessagingProvider, 404);
        $request = Request::create('/api/v1/webhooks/inbound/fake', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $provider->sign($body, $secrets->forTenant($tenantId, 'fake')),
        ], $body);
        $response = app(InboundWebhookController::class)($request, 'fake', $providers, $secrets, $processor);
        abort_unless($response->getStatusCode() === 204, $response->getStatusCode(), 'The simulated reply was rejected by the inbound webhook processor.');
        return $this->status($fixture);
    }

    private function authorizeAcceptance(LocalAcceptanceFixture $fixture, string $tenantId): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('ai.local_acceptance.enabled') && $fixture->isAcceptanceTenant($tenantId), 404);
        $role = request()->user()?->tenants()->whereKey($tenantId)->wherePivot('status', 'active')->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }
}
