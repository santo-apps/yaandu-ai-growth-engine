<?php

namespace App\Messaging;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FakeOutboundMessagingProvider implements OutboundMessagingProviderInterface
{
    /** @var array<string, array{fingerprint: string, result: OutboundSendResult}> */
    private array $receipts = [];

    public function providerKey(): string { return 'fake'; }
    public function capabilities(): array { return ['send', 'status', 'retry', 'webhook_events']; }

    public function send(OutboundMessageRequest $message, string $idempotencyKey): OutboundSendResult
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('The outbound idempotency key is invalid.');
        }

        $fingerprint = hash('sha256', json_encode([
            $message->tenantId, $message->senderEmail, $message->recipientEmail,
            $message->subject, $message->body,
        ], JSON_THROW_ON_ERROR));
        if (isset($this->receipts[$idempotencyKey])) {
            if (! hash_equals($this->receipts[$idempotencyKey]['fingerprint'], $fingerprint)) {
                throw new InvalidArgumentException('The outbound idempotency key belongs to a different message.');
            }

            return $this->receipts[$idempotencyKey]['result'];
        }

        $result = new OutboundSendResult('fake-'.Str::uuid(), OutboundMessageStatus::Accepted, new DateTimeImmutable);
        $this->receipts[$idempotencyKey] = ['fingerprint' => $fingerprint, 'result' => $result];

        return $result;
    }

    public function status(string $providerMessageId): OutboundMessageStatus
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt['result']->providerMessageId === $providerMessageId) return $receipt['result']->status;
        }

        return OutboundMessageStatus::Unknown;
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function normalizeWebhookEvent(array $payload): OutboundProviderEvent
    {
        $eventId = $payload['event_id'] ?? null;
        $providerMessageId = $payload['message_id'] ?? null;
        $status = $payload['status'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;
        $mappedStatus = is_string($status) ? OutboundMessageStatus::tryFrom($status) : null;
        $eventStatuses = [OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered, OutboundMessageStatus::Deferred,
            OutboundMessageStatus::SoftBounced, OutboundMessageStatus::Bounced, OutboundMessageStatus::Complained,
            OutboundMessageStatus::Unsubscribed, OutboundMessageStatus::Failed];
        if (! is_string($eventId) || $eventId === '' || strlen($eventId) > 180
            || ! is_string($providerMessageId) || $providerMessageId === '' || strlen($providerMessageId) > 180
            || ! $mappedStatus || ! in_array($mappedStatus, $eventStatuses, true) || ! is_string($occurredAt)) {
            throw new InvalidArgumentException('The provider event is invalid.');
        }

        try { $timestamp = new DateTimeImmutable($occurredAt); }
        catch (\Throwable $exception) { throw new InvalidArgumentException('The provider event timestamp is invalid.', previous: $exception); }

        return new OutboundProviderEvent($eventId, $providerMessageId, $mappedStatus, $timestamp);
    }
}
