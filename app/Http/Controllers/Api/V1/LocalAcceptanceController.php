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
        $messageContext = DB::table('outbound_messages as messages')
            ->join('campaign_recipients as recipients', function ($join): void {
                $join->on('recipients.tenant_id', '=', 'messages.tenant_id')->on('recipients.id', '=', 'messages.campaign_recipient_id');
            })->join('companies', function ($join): void {
                $join->on('companies.tenant_id', '=', 'recipients.tenant_id')->on('companies.id', '=', 'recipients.company_id');
            })->where('messages.tenant_id', $tenantId)->where('messages.provider', 'fake')
            ->where(function ($query): void {
                $query->where('companies.source', 'local_acceptance_fixture')
                    ->orWhere('companies.source', 'like', 'csv_import:sprint7_simulated_fixture%');
            })->orderByDesc('messages.created_at')->select([
                'messages.id', 'messages.status', 'messages.provider', 'messages.campaign_id', 'recipients.contact_id', 'recipients.company_id',
            ])->first();
        $message = $messageContext ? (object) ['id' => $messageContext->id, 'status' => $messageContext->status, 'provider' => $messageContext->provider] : null;
        $conversation = $messageContext ? DB::table('conversations')->where('tenant_id', $tenantId)
            ->where('company_id', $messageContext->company_id)->where('contact_id', $messageContext->contact_id)->orderByDesc('updated_at')->first(['id']) : null;
        $inboundCount = $conversation ? DB::table('conversation_messages')->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversation->id)->where('direction', 'inbound')->count() : 0;
        $opportunity = $messageContext ? DB::table('sales_opportunities')->where('tenant_id', $tenantId)
            ->where('company_id', $messageContext->company_id)->orderByDesc('created_at')->first(['id']) : null;

        return response()->json(['mode' => 'LOCAL ACCEPTANCE', 'providers' => ['ai' => 'DeterministicAIProvider', 'outbound' => 'FakeOutboundMessagingProvider',
            'scheduling' => 'FakeSchedulingProvider'], 'queue' => config('queue.default'), 'company_id' => $messageContext?->company_id,
            'campaign_id' => $messageContext?->campaign_id,
            'campaign_status' => $messageContext ? DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $messageContext->campaign_id)->value('status') : null,
            'message' => $message, 'inbound_count' => $inboundCount,
            'conversation_id' => $conversation?->id, 'opportunity_id' => $opportunity?->id,
            'meeting_id' => $opportunity ? DB::table('meeting_bookings')->where('tenant_id', $tenantId)->where('sales_opportunity_id', $opportunity->id)->orderByDesc('created_at')->value('id') : null,
            'proposal_id' => $opportunity ? DB::table('proposals')->where('tenant_id', $tenantId)->where('sales_opportunity_id', $opportunity->id)->orderByDesc('created_at')->value('id') : null,
            'proposal_delivery_count' => $opportunity ? DB::table('proposal_deliveries')->where('tenant_id', $tenantId)->whereIn('proposal_id',
                DB::table('proposals')->where('tenant_id', $tenantId)->where('sales_opportunity_id', $opportunity->id)->select('id'))->count() : 0]);
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
        $message = DB::table('outbound_messages as messages')
            ->join('campaign_recipients as recipients', function ($join): void {
                $join->on('recipients.id', '=', 'messages.campaign_recipient_id')->on('recipients.tenant_id', '=', 'messages.tenant_id');
            })->join('companies', function ($join): void {
                $join->on('companies.id', '=', 'recipients.company_id')->on('companies.tenant_id', '=', 'recipients.tenant_id');
            })->where('messages.tenant_id', $tenantId)->where('messages.provider', 'fake')->where('messages.status', 'sent')
            ->where(function ($query): void {
                $query->where('companies.source', 'local_acceptance_fixture')
                    ->orWhere('companies.source', 'like', 'csv_import:sprint7_simulated_fixture%');
            })
            ->orderByDesc('messages.sent_at')->first(['messages.id', 'recipients.contact_method_id']);
        abort_unless($message, 409, 'A fake outbound message for a local acceptance fixture must be marked sent before simulating a prospect reply.');
        $contactMethod = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('id', $message->contact_method_id)->where('type', 'email')->first();
        abort_unless($contactMethod, 404);
        $contactEmail = $values->decrypt($contactMethod->value);
        abort_unless(str_ends_with(strtolower($contactEmail), '.fixture.test'), 404);
        $bodyText = $data['intent'] === 'unsubscribe'
            ? 'UNSUBSCRIBE'
            : 'We are interested in discussing ecommerce modernization and improving our mobile experience. Can we book a meeting next week and receive a proposal? Our budget is still being reviewed.';
        $payload = ['tenant_id' => $tenantId, 'event_id' => 'local-acceptance-reply-'.$message->id, 'sender_email' => $contactEmail,
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
