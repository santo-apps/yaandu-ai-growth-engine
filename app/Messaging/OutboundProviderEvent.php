<?php

namespace App\Messaging;

use DateTimeImmutable;

final readonly class OutboundProviderEvent
{
    public function __construct(
        public string $eventId,
        public string $providerMessageId,
        public OutboundMessageStatus $status,
        public DateTimeImmutable $occurredAt,
    ) {}
}
