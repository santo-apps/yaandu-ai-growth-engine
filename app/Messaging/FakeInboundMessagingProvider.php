<?php

namespace App\Messaging;

use DateTimeImmutable;
use InvalidArgumentException;

final class FakeInboundMessagingProvider implements InboundMessagingProviderInterface
{
    public function providerKey(): string { return 'fake'; }
    public function sign(string $rawBody, string $secret): string { return hash_hmac('sha256', $rawBody, $secret); }
    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        return hash_equals($this->sign($rawBody, $secret), $signature);
    }

    public function normalizeInboundEvent(array $payload): InboundMessageEvent
    {
        $tenantId = $payload['tenant_id'] ?? null;
        $eventId = $payload['event_id'] ?? null;
        $sender = $payload['sender_email'] ?? null;
        $body = $payload['body'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;
        if (! is_string($tenantId) || ! \Illuminate\Support\Str::isUuid($tenantId)
            || ! is_string($eventId) || $eventId === '' || strlen($eventId) > 180
            || ! is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false || strlen($sender) > 254
            || ! is_string($body) || trim($body) === '' || strlen($body) > 16000 || ! is_string($occurredAt)) {
            throw new InvalidArgumentException('Inbound provider event is invalid.');
        }
        try { $time = new DateTimeImmutable($occurredAt); }
        catch (\Throwable $exception) { throw new InvalidArgumentException('Inbound provider timestamp is invalid.', previous: $exception); }

        return new InboundMessageEvent($tenantId, $eventId, mb_strtolower($sender), $body, $time);
    }
}
