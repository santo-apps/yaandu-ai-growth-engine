<?php

namespace App\WebsiteResolution;

interface WebsiteResolutionSourceInterface
{
    public function name(): string;

    /** Return only URL-backed candidates or evidence attached to an existing URL hint. */
    public function find(array $identity, array $knownCandidates = []): array;
}
