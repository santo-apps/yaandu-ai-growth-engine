<?php

namespace App\Messaging;

interface OutboundMessagingProviderInterface
{
    public function providerKey(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** Send plain-text content; adapters must honor the idempotency key across retries. */
    public function send(OutboundMessageRequest $message, string $idempotencyKey): OutboundSendResult;

    public function status(string $providerMessageId): OutboundMessageStatus;

    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool;

    public function normalizeWebhookEvent(array $payload): OutboundProviderEvent;
}
