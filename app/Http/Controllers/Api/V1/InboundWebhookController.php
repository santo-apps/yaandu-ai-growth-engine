<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFollowUpReply;
use App\Messaging\InboundMessageProcessor;
use App\Messaging\InboundMessagingProviderRouter;
use App\Messaging\TenantWebhookSecretResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboundWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, InboundMessagingProviderRouter $providers,
        TenantWebhookSecretResolver $secrets, InboundMessageProcessor $processor)
    {
        $rawBody = $request->getContent();
        abort_if(strlen($rawBody) > 65536, 413, 'Webhook payload is too large.');
        $payload = json_decode($rawBody, true);
        abort_unless(is_array($payload) && is_string($payload['tenant_id'] ?? null), 422, 'Webhook payload is invalid.');
        $tenantId = $payload['tenant_id'];
        abort_unless(DB::table('tenants')->where('id', $tenantId)->where('status', 'active')->exists(), 404);
        $signature = $request->header('X-Provider-Signature', '');
        try {
            $adapter = $providers->forTenant($tenantId, $provider);
            if (! $adapter->verifyWebhookSignature($rawBody, $signature, $secrets->forTenant($tenantId, $provider))) {
                return response()->json(['message' => 'Webhook signature is invalid.'], 403);
            }
            $event = $adapter->normalizeInboundEvent($payload);
            if ($event->tenantId !== $tenantId) return response()->json(['message' => 'Webhook tenant is invalid.'], 403);
            $conversationId = $processor->process($provider, $event);
            if ($conversationId !== null) {
                app(\App\Orchestration\WorkflowService::class)->recordConversationEvent($tenantId, $conversationId, 'reply_received',
                    'reply-received:'.$provider.':'.$event->eventId);
                ProcessFollowUpReply::dispatch($tenantId, $conversationId)->afterCommit();
            }

            return response()->noContent();
        } catch (Throwable $exception) {
            Log::warning('Inbound message webhook was rejected.', ['tenant_id' => $tenantId,
                'provider' => $provider, 'event_id' => is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null,
                'exception' => class_basename($exception)]);

            return response()->json(['message' => 'Inbound webhook could not be processed.'], 422);
        }
    }
}
