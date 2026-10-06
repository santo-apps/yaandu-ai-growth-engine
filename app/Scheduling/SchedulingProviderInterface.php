<?php

namespace App\Scheduling;

use DateTimeImmutable;

interface SchedulingProviderInterface
{
    public function providerKey(): string;
    public function availability(string $timezone, DateTimeImmutable $from, DateTimeImmutable $to, int $durationMinutes = 30): array;
    public function book(string $timezone, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $idempotencyKey): SchedulingResult;
    public function reschedule(string $providerBookingId, string $timezone, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $idempotencyKey): SchedulingResult;
    public function cancel(string $providerBookingId, string $idempotencyKey): void;
    public function getMeeting(string $providerBookingId): ?SchedulingResult;
}
