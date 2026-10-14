<?php

namespace App\Outreach;

final readonly class OutreachDraftGroundingResult
{
    /** @param list<array{reason_code: string, field: string}> $violations */
    public function __construct(public bool $valid, public array $violations) {}
}
