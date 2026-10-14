<?php

namespace App\Outreach;

/** Immutable, reviewer-selected evidence supplied to the deterministic composer. */
final readonly class OutreachEvidenceAtom
{
    /** @param array<string, mixed> $evidenceReference */
    public function __construct(
        public string $findingId,
        public string $label,
        public string $observation,
        public string $opportunity,
        public string $evidenceSummary,
        public array $evidenceReference,
    ) {}
}
