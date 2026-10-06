<?php

namespace Tests\Unit;

use App\Campaigns\SendingWindowCalculator;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SendingWindowCalculatorTest extends TestCase
{
    public function test_calculates_first_available_local_send_time(): void
    {
        $campaign = new Campaign(['timezone' => 'Asia/Kolkata', 'sending_windows' => ['weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00']]);
        $calculator = new SendingWindowCalculator;

        self::assertSame('2026-10-05 09:00:00 +05:30', $calculator->nextAllowedTime($campaign, CarbonImmutable::parse('2026-10-05 03:30:00 UTC'))->format('Y-m-d H:i:s P'));
        self::assertSame('2026-10-06 09:00:00 +05:30', $calculator->nextAllowedTime($campaign, CarbonImmutable::parse('2026-10-05 12:00:00 UTC'))->format('Y-m-d H:i:s P'));
    }

    public function test_campaign_without_a_send_window_keeps_the_candidate_in_campaign_timezone(): void
    {
        $campaign = new Campaign(['timezone' => 'America/Toronto']);
        $candidate = CarbonImmutable::parse('2026-10-05 12:00:00 UTC');

        self::assertSame('2026-10-05 08:00:00 -04:00', (new SendingWindowCalculator)->nextAllowedTime($campaign, $candidate)->format('Y-m-d H:i:s P'));
    }
}
