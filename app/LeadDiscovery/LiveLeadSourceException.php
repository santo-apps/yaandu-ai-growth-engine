<?php

namespace App\LeadDiscovery;

use RuntimeException;

final class LiveLeadSourceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $safeMessage, public readonly ?int $retryAfter = null)
    {
        parent::__construct($safeMessage);
    }
}
