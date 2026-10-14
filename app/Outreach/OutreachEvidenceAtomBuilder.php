<?php

namespace App\Outreach;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Builds immutable copy inputs only from findings already selected and validated for this draft. */
final class OutreachEvidenceAtomBuilder
{
    /** @param list<array<string, mixed>> $selectedFindings @return list<OutreachEvidenceAtom> */
    public function build(array $selectedFindings): array
    {
        $atoms = [];
        foreach ($selectedFindings as $finding) {
            $id = $finding['id'] ?? null;
            $labelValue = $finding['label'] ?? null;
            $observationValue = $finding['evidence'] ?? null;
            $opportunityValue = $finding['summary'] ?? null;
            $label = is_string($labelValue) ? trim($labelValue) : '';
            $observation = is_string($observationValue) ? trim($observationValue) : '';
            $opportunity = is_string($opportunityValue) ? trim($opportunityValue) : '';
            $reference = $finding['evidence_reference'] ?? null;

            $source = is_array($reference) ? ($reference['source'] ?? null) : null;
            $referenceId = is_array($reference) ? ($reference['id'] ?? null) : null;
            if (! is_string($id) || ! Str::isUuid($id) || $label === '' || $observation === '' || $opportunity === ''
                || ! is_array($reference) || ! in_array($source, ['website_issue', 'business_insight', 'lead_evidence'], true)
                || ! is_string($referenceId) || ! Str::isUuid($referenceId)) {
                throw new InvalidArgumentException('A selected outreach finding is missing validated evidence provenance.');
            }

            $atoms[] = new OutreachEvidenceAtom($id, $label, $observation, $opportunity, $observation, $reference);
        }

        if ($atoms === []) {
            throw new InvalidArgumentException('At least one selected evidence atom is required.');
        }

        return $atoms;
    }
}
