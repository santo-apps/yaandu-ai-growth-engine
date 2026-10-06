<?php

namespace App\Messaging;

use DateTimeImmutable;

final readonly class InboundMessageEvent
{
    public function __construct(public string $tenantId, public string $eventId, public string $senderEmail,
        public string $body, public DateTimeImmutable $occurredAt) {}
}
