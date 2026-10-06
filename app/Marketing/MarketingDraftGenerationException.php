<?php

namespace App\Marketing;

use RuntimeException;

final class MarketingDraftGenerationException extends RuntimeException
{
    public function __construct(public readonly string $correlationId, ?\Throwable $previous = null)
    {
        parent::__construct('A safe marketing draft could not be created.', previous: $previous);
    }
}
