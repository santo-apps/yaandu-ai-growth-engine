<?php

namespace App\LeadScoring;

final class ScoringRuleEvaluator
{
    public const MINIMUM_EVIDENCE_COVERAGE_PERCENT = 30;
    public const MINIMUM_EVALUABLE_DIMENSIONS = 3;
    private const DIGITAL_DIMENSIONS = ['outdated_website', 'poor_mobile_ux', 'poor_lead_capture', 'no_whatsapp', 'no_crm', 'technology_opportunity', 'strong_business_fit', 'digital_opportunity', 'service_relevance'];
    public const DEFAULT_RULES = [
        'icp_fit' => 10, 'relevant_industry' => 10, 'outdated_website' => 15,
        'poor_mobile_ux' => 10, 'poor_lead_capture' => 10, 'no_whatsapp' => 10,
        'no_crm' => 10, 'technology_opportunity' => 5,
        'decision_maker_identified' => 10, 'strong_business_fit' => 10,
    ];

    /** @return array{score:?int,evaluation_status:string,evaluated_points:int,configured_points:int,evidence_coverage:int,components:array,rule_version:int} */
    public function score(array $evidence, array $rules = self::DEFAULT_RULES, int $version = 1): array
    {
        $components = [];
        foreach ($rules as $key => $points) {
            $rawStatus = strtolower((string) ($evidence[$key]['status'] ?? (($evidence[$key]['present'] ?? false) ? 'positive' : 'unknown')));
            $status = match ($rawStatus) {
                'confirmed_present', 'positive' => 'POSITIVE',
                'confirmed_absent', 'negative' => 'NEGATIVE',
                'not_configured' => 'NOT_CONFIGURED',
                default => 'UNKNOWN',
            };
            $included = in_array($status, ['POSITIVE', 'NEGATIVE'], true);
            $positive = $status === 'POSITIVE';
            $weight = max(0, (int) $points);
            $reference = $evidence[$key]['reference'] ?? null;
            $reason = $evidence[$key]['reason'] ?? match ($status) {
                'POSITIVE' => 'Evidence supports this scoring dimension.',
                'NEGATIVE' => 'Evidence contradicts this scoring dimension.',
                'NOT_CONFIGURED' => 'This scoring dimension is not configured.',
                default => 'Available evidence is insufficient to evaluate this dimension.',
            };
            $components[$key] = [
                'dimension' => $key,
                'status' => $status,
                'raw_points' => $positive ? $weight : 0,
                'possible_points' => $included ? $weight : 0,
                'evidence_reference' => $reference,
                'reason' => $reason,
                'included_in_denominator' => $included,
                // Legacy keys remain for API consumers during the pilot transition.
                'points' => $positive ? $weight : 0,
                'max_points' => $weight,
                'present' => $positive,
                'evidence' => $reference,
            ];
        }
        $configured = array_sum(array_map('intval', $rules));
        $possible = array_sum(array_column($components, 'possible_points'));
        $earned = array_sum(array_column($components, 'raw_points'));
        $coverage = $configured > 0 ? (int) round(($possible / $configured) * 100) : 0;
        $evaluatedDimensions = count(array_filter($components, static fn (array $component): bool => $component['included_in_denominator']));
        $digitalOpportunityEvaluated = false;
        foreach (self::DIGITAL_DIMENSIONS as $key) {
            if (in_array($components[$key]['status'] ?? null, ['POSITIVE', 'NEGATIVE'], true)) {
                $digitalOpportunityEvaluated = true;
                break;
            }
        }
        $scoreable = $possible > 0 && $coverage >= self::MINIMUM_EVIDENCE_COVERAGE_PERCENT
            && $evaluatedDimensions >= self::MINIMUM_EVALUABLE_DIMENSIONS && $digitalOpportunityEvaluated;
        $score = $scoreable ? (int) round(($earned / $possible) * 100) : null;
        return [
            'score' => $score === null ? null : min(100, max(0, $score)),
            'evaluation_status' => $scoreable ? 'scored' : 'insufficient_evidence',
            'evaluated_points' => $possible,
            'configured_points' => $configured,
            'evidence_coverage' => $coverage,
            'minimum_evidence_coverage' => self::MINIMUM_EVIDENCE_COVERAGE_PERCENT,
            'minimum_evaluable_dimensions' => self::MINIMUM_EVALUABLE_DIMENSIONS,
            'digital_opportunity_evaluated' => $digitalOpportunityEvaluated,
            'insufficient_evidence_reasons' => $scoreable ? [] : array_values(array_filter([
                $possible === 0 ? 'no_evaluable_dimensions' : null,
                $possible > 0 && $coverage < self::MINIMUM_EVIDENCE_COVERAGE_PERCENT ? 'below_minimum_coverage' : null,
                $possible > 0 && $evaluatedDimensions < self::MINIMUM_EVALUABLE_DIMENSIONS ? 'too_few_evaluable_dimensions' : null,
                ! $digitalOpportunityEvaluated ? 'no_evaluable_digital_opportunity' : null,
            ])),
            'components' => $components,
            'rule_version' => $version,
        ];
    }
}
