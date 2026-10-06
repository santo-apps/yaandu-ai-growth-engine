<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Messaging\MessageEventProcessor;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Messaging\TenantWebhookSecretResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OutboundWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, OutboundMessagingProviderRouter $providers, TenantWebhookSecretResolver $secrets, MessageEventProcessor $events)
    {
        abort_if(strlen($request->getContent()) > 65536, 413, 'Webhook payload exceeds the size limit.');
        abort_unless(in_array($provider, config('outbound.enabled_providers', []), true), 404);
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload) && isset($payload['tenant_id']) && is_string($payload['tenant_id']) && Str::isUuid($payload['tenant_id']), 422, 'Webhook payload is invalid.');
        $tenantId = $payload['tenant_id'];
        $configuration = \Illuminate\Support\Facades\DB::table('tenant_messaging_configurations')
            ->where('tenant_id', $tenantId)->where('provider', $provider)->where('enabled', true)->first();
        abort_unless($configuration, 404);
        $adapter = $providers->forTenant($tenantId);
        abort_unless(in_array('webhook_events', $adapter->capabilities(), true), 404);
        $signature = (string) $request->header('X-Provider-Signature', '');
        abort_unless($signature !== '' && $adapter->verifyWebhookSignature($request->getContent(), $signature, $secrets->forTenant($tenantId, $provider)), 403, 'Webhook signature is invalid.');

        try {
            $event = $adapter->normalizeWebhookEvent($payload);
            $events->process($tenantId, $provider, $event);
        } catch (\Throwable) {
            return response()->json(['message' => 'Provider event could not be accepted.'], 422);
        }

        return response()->noContent();
    }
}
