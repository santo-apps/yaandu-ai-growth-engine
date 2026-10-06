<?php

namespace App\LeadScoring;

final class ScoringRuleEvaluator
{
    public const DEFAULT_RULES = [
        'icp_fit' => 10, 'relevant_industry' => 10, 'outdated_website' => 15,
        'poor_mobile_ux' => 10, 'poor_lead_capture' => 10, 'no_whatsapp' => 10,
        'no_crm' => 10, 'technology_opportunity' => 5,
        'decision_maker_identified' => 10, 'strong_business_fit' => 10,
    ];

    /** @return array{score:int,components:array,rule_version:int} */
    public function score(array $evidence, array $rules = self::DEFAULT_RULES, int $version = 1): array
    {
        $components = [];
        foreach ($rules as $key => $points) {
            $status = $evidence[$key]['status'] ?? (($evidence[$key]['present'] ?? false) ? 'confirmed_present' : 'unknown');
            if (! in_array($status, ['confirmed_present', 'confirmed_absent', 'unknown'], true)) $status = 'unknown';
            $present = $status === 'confirmed_present';
            $components[$key] = ['points' => $present ? (int) $points : 0, 'max_points' => (int) $points,
                'present' => $present, 'status' => $status, 'evidence' => $evidence[$key]['reference'] ?? null];
        }
        $possible = array_sum(array_map('intval', $rules));
        $earned = array_sum(array_column($components, 'points'));
        $score = $possible > 0 ? (int) round(($earned / $possible) * 100) : 0;
        return ['score' => min(100, max(0, $score)), 'components' => $components, 'rule_version' => $version];
    }
}
