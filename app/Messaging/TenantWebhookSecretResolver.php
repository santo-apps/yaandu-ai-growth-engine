<?php

namespace App\Messaging;

final class TenantWebhookSecretResolver
{
    public function forTenant(string $tenantId, string $provider): string
    {
        return hash_hmac('sha256', 'outbound-webhook:'.$provider.':'.$tenantId, (string) config('app.key'));
    }
}
