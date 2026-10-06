<?php

namespace App\Orchestration;

final class ConfidencePolicy
{
    /** Confidence is advisory; safety intent, evidence, and completeness can only make the decision stricter. */
    public function evaluate(float $confidence, string $intent, string $risk, int $evidenceCount, int $requiredEvidence,
        bool $qualificationComplete, string $action): array
    {
        $confidence = max(0, min(1, $confidence));
        $intent = strtolower($intent);
        $risk = strtoupper($risk);
        if (in_array($intent, ['unsubscribe', 'not_interested', 'wrong_contact'], true)) {
            return ['decision' => 'STOP', 'reasons' => ['terminal_contact_intent']];
        }
        if (in_array($risk, ['CRITICAL', 'HIGH'], true) || in_array($intent, ['pricing_request', 'legal', 'complaint', 'proposal_request'], true)) {
            return ['decision' => 'HUMAN_REVIEW', 'reasons' => ['high_risk_context']];
        }
        $reasons = [];
        $minimum = (float) config('orchestration.confidence_minimums.'.$action, 0.8);
        if ($confidence < $minimum) $reasons[] = 'confidence_below_task_threshold';
        if ($requiredEvidence > 0 && $evidenceCount < $requiredEvidence) $reasons[] = 'evidence_incomplete';
        if (in_array($action, ['CREATE_OPPORTUNITY', 'GENERATE_PROPOSAL'], true) && ! $qualificationComplete) $reasons[] = 'qualification_incomplete';
        if ($reasons !== []) return ['decision' => 'APPROVAL_REQUIRED', 'reasons' => $reasons];
        return ['decision' => 'CONTINUE', 'reasons' => []];
    }
}
