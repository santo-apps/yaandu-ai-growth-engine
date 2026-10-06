<?php

namespace App\Scheduling;

use DateTimeImmutable;

final readonly class SchedulingResult
{
    public function __construct(public string $providerBookingId, public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt) {}
}
