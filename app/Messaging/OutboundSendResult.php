<?php

namespace App\Messaging;

use DateTimeImmutable;

final readonly class OutboundSendResult
{
    public function __construct(
        public string $providerMessageId,
        public OutboundMessageStatus $status,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
