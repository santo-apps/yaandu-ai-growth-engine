<?php

namespace App\Scheduling;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

final class FakeSchedulingProvider implements SchedulingProviderInterface
{
    /** @var array<string, SchedulingResult> */
    private array $bookings = [];
    private bool $failNextBooking = false;

    public function providerKey(): string { return 'fake'; }

    public function availability(string $timezone, DateTimeImmutable $from, DateTimeImmutable $to, int $durationMinutes = 30): array
    {
        $zone = new DateTimeZone($timezone);
        $cursor = $from->setTimezone($zone)->setTime(9, 0);
        $slots = [];
        while ($cursor < $to && count($slots) < 120) {
            if ((int) $cursor->format('N') <= 5 && $cursor > new DateTimeImmutable('now', $zone)) {
                for ($hour = 9; $hour < 17 && count($slots) < 120; $hour++) {
                    $start = $cursor->setTime($hour, 0);
                    if ($start >= $from->setTimezone($zone) && $start->add(new DateInterval('PT'.$durationMinutes.'M')) <= $to->setTimezone($zone)) {
                        $slots[] = ['starts_at' => $start, 'ends_at' => $start->add(new DateInterval('PT'.$durationMinutes.'M')), 'timezone' => $timezone];
                    }
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $slots;
    }

    public function book(string $timezone, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $idempotencyKey): SchedulingResult
    {
        if ($this->failNextBooking) { $this->failNextBooking = false; throw new \RuntimeException('Simulated scheduling provider failure.'); }
        return $this->bookings[$idempotencyKey] ??= new SchedulingResult('fake-'.Str::uuid(), $startsAt, $endsAt);
    }

    public function failNextBooking(): void { $this->failNextBooking = true; }

    public function reschedule(string $providerBookingId, string $timezone, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $idempotencyKey): SchedulingResult
    {
        return $this->bookings[$idempotencyKey] ??= new SchedulingResult($providerBookingId, $startsAt, $endsAt);
    }

    public function cancel(string $providerBookingId, string $idempotencyKey): void {}

    public function getMeeting(string $providerBookingId): ?SchedulingResult
    {
        foreach ($this->bookings as $booking) if ($booking->providerBookingId === $providerBookingId) return $booking;
        return null;
    }
}
