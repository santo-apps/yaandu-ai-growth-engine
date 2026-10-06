<?php

namespace Tests\Unit;

use App\LeadScoring\ScoringRuleEvaluator;
use PHPUnit\Framework\TestCase;

class ScoringRuleEvaluatorTest extends TestCase
{
    public function test_empty_evidence_scores_zero_and_preserves_rule_coverage(): void
    {
        $result = (new ScoringRuleEvaluator())->score([]);
        self::assertSame(0, $result['score']);
        self::assertCount(10, $result['components']);
    }

    public function test_all_default_rules_normalize_to_one_hundred(): void
    {
        $evidence = array_fill_keys(array_keys(ScoringRuleEvaluator::DEFAULT_RULES), ['present' => true]);
        $result = (new ScoringRuleEvaluator())->score($evidence);
        self::assertSame(100, $result['score']);
    }

    public function test_configured_weights_are_normalized_and_versioned(): void
    {
        $result = (new ScoringRuleEvaluator())->score(['fit' => ['present' => true]], ['fit' => 7, 'industry' => 3], 4);
        self::assertSame(70, $result['score']);
        self::assertSame(4, $result['rule_version']);
    }

    public function test_unknown_evidence_does_not_award_absence_or_presence_points(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'no_crm' => ['status' => 'unknown'],
            'no_whatsapp' => ['status' => 'unknown'],
            'poor_lead_capture' => ['status' => 'unknown'],
            'decision_maker_identified' => ['status' => 'unknown'],
        ], ['no_crm' => 10, 'no_whatsapp' => 10, 'poor_lead_capture' => 10, 'decision_maker_identified' => 10]);

        self::assertSame(0, $result['score']);
        foreach ($result['components'] as $component) {
            self::assertSame('unknown', $component['status']);
            self::assertSame(0, $component['points']);
        }
    }
}
