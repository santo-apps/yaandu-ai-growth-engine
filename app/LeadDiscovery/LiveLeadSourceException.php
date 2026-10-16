<?php

namespace App\LeadDiscovery;

use RuntimeException;

final class LiveLeadSourceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $safeMessage, public readonly ?int $retryAfter = null)
    {
        parent::__construct($safeMessage);
    }

    public static function retryAfterSeconds(?string $header, int $default = 10): int
    {
        $default = min(3600, max(1, $default));
        if ($header === null || trim($header) === '') return $default;
        $header = trim($header);
        if (ctype_digit($header)) return min(3600, max(1, (int) $header));
        $timestamp = strtotime($header);

        return $timestamp === false ? $default : min(3600, max(1, $timestamp - time()));
    }
}
