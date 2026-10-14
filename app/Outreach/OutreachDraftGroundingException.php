<?php

namespace App\Outreach;

use RuntimeException;

final class OutreachDraftGroundingException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $field,
        /** @var list<array{reason_code: string, field: string}> */
        public readonly array $violations = [],
    ) {
        parent::__construct('The generated outreach draft failed grounding validation.');
    }
}
