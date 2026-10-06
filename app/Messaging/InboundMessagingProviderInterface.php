<?php

namespace App\Messaging;

interface InboundMessagingProviderInterface
{
    public function providerKey(): string;
    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool;
    public function normalizeInboundEvent(array $payload): InboundMessageEvent;
}
