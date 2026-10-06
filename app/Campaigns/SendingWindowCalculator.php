<?php

namespace App\Campaigns;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SendingWindowCalculator
{
    public function nextAllowedTime(Campaign $campaign, CarbonImmutable $candidate): CarbonImmutable
    {
        $timezone = $campaign->timezone ?: 'UTC';
        try { $local = $candidate->setTimezone($timezone); }
        catch (\Throwable $exception) { throw new InvalidArgumentException('Campaign timezone is invalid.', previous: $exception); }

        $window = $campaign->sending_windows;
        if (! is_array($window) || ! isset($window['weekdays'], $window['start'], $window['end'])) return $local;
        $weekdays = array_values(array_unique(array_map('intval', $window['weekdays'])));
        $start = (string) $window['start'];
        $end = (string) $window['end'];
        if ($weekdays === [] || ! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
            throw new InvalidArgumentException('Campaign sending window is invalid.');
        }

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $local->addDays($offset);
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) continue;
            [$hour, $minute] = array_map('intval', explode(':', $start));
            $opens = $day->setTime($hour, $minute);
            [$endHour, $endMinute] = array_map('intval', explode(':', $end));
            $closes = $day->setTime($endHour, $endMinute);
            if ($local < $opens) return $opens;
            if ($local >= $opens && $local < $closes) return $local;
        }

        throw new InvalidArgumentException('No available campaign sending window was found.');
    }
}
