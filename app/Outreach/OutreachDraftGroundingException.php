<?php

namespace App\Outreach;

use RuntimeException;

final class OutreachDraftGroundingException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $field,
    ) {
        parent::__construct('The generated outreach draft failed grounding validation.');
    }
}
