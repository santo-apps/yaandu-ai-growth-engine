<?php

namespace App\Proposals;

use InvalidArgumentException;

final class DecimalMoney
{
    public static function cents(string|int $amount): int
    {
        $amount = trim((string) $amount);
        if (! preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/', $amount, $m)) throw new InvalidArgumentException('Money must be a non-negative decimal with at most two fractional digits.');
        return ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) throw new InvalidArgumentException('Money cannot be negative.');
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function basisPoints(string|int|float $percent): int
    {
        $value = trim((string) $percent);
        if (! preg_match('/^(?:0|[1-9][0-9]?|100)(?:\.([0-9]{1,2}))?$/', $value, $m)) throw new InvalidArgumentException('Percentage must be between 0 and 100 with at most two decimals.');
        $whole = (int) explode('.', $value)[0];
        return $whole * 100 + (int) str_pad($m[1] ?? '', 2, '0');
    }
}
