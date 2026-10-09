<?php

namespace Tests\Unit;

use App\LeadScoring\ScoringRuleEvaluator;
use PHPUnit\Framework\TestCase;

class ScoringRuleEvaluatorTest extends TestCase
{
    public function test_empty_evidence_is_unscored_and_preserves_unknown_rule_coverage(): void
    {
        $result = (new ScoringRuleEvaluator())->score([]);
        self::assertNull($result['score']);
        self::assertSame('insufficient_evidence', $result['evaluation_status']);
        self::assertSame(0, $result['evidence_coverage']);
        self::assertCount(10, $result['components']);
    }

    public function test_all_default_rules_normalize_to_one_hundred(): void
    {
        $evidence = array_fill_keys(array_keys(ScoringRuleEvaluator::DEFAULT_RULES), ['present' => true]);
        $result = (new ScoringRuleEvaluator())->score($evidence);
        self::assertSame(100, $result['score']);
        self::assertSame('scored', $result['evaluation_status']);
    }

    public function test_configured_weights_are_normalized_and_versioned(): void
    {
        $result = (new ScoringRuleEvaluator())->score(['fit' => ['present' => true]], ['fit' => 7, 'industry' => 3], 4);
        self::assertNull($result['score']);
        self::assertSame('insufficient_evidence', $result['evaluation_status']);
        self::assertSame(70, $result['evidence_coverage']);
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

        self::assertNull($result['score']);
        self::assertSame('insufficient_evidence', $result['evaluation_status']);
        foreach ($result['components'] as $component) {
            self::assertSame('UNKNOWN', $component['status']);
            self::assertSame(0, $component['points']);
        }
    }

    public function test_unknown_dimensions_do_not_reduce_score_when_known_evidence_exists(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'fit' => ['status' => 'confirmed_present'],
            'industry' => ['status' => 'unknown'],
        ], ['fit' => 10, 'industry' => 10]);

        self::assertNull($result['score']);
        self::assertSame('insufficient_evidence', $result['evaluation_status']);
        self::assertSame(10, $result['evaluated_points']);
        self::assertSame(50, $result['evidence_coverage']);
    }

    public function test_confirmed_absence_is_evaluated_as_zero_and_not_as_unknown(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'fit' => ['status' => 'confirmed_absent'],
            'industry' => ['status' => 'confirmed_present'],
        ], ['fit' => 10, 'industry' => 10]);

        self::assertNull($result['score']);
        self::assertSame(100, $result['evidence_coverage']);
    }

    public function test_strong_evidence_scores_all_confirmed_positive_dimensions(): void
    {
        $rules = ['geography_fit' => 10, 'industry_fit' => 10, 'digital_opportunity' => 15, 'service_relevance' => 10];
        $evidence = array_fill_keys(array_keys($rules), ['status' => 'confirmed_present']);
        self::assertSame(100, (new ScoringRuleEvaluator())->score($evidence, $rules)['score']);
    }

    public function test_conflicting_evidence_keeps_confirmed_absence_as_zero_and_unknown_out_of_denominator(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'geography_fit' => ['status' => 'confirmed_present'],
            'service_relevance' => ['status' => 'confirmed_absent'],
            'digital_opportunity' => ['status' => 'unknown'],
            'organization_fit' => ['status' => 'confirmed_present'],
        ], ['geography_fit' => 10, 'service_relevance' => 10, 'digital_opportunity' => 20, 'organization_fit' => 10]);
        self::assertSame(67, $result['score']);
        self::assertSame(60, $result['evidence_coverage']);
        self::assertSame('NEGATIVE', $result['components']['service_relevance']['status']);
        self::assertSame('UNKNOWN', $result['components']['digital_opportunity']['status']);
    }

    public function test_industry_and_geography_remain_separate_dimensions_when_both_match(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'icp_fit' => ['status' => 'confirmed_present', 'reference' => ['source' => 'location']],
            'relevant_industry' => ['status' => 'confirmed_present', 'reference' => ['source' => 'industry']],
        ], ['icp_fit' => 10, 'relevant_industry' => 10]);
        self::assertNull($result['score']);
        self::assertSame(20, $result['evaluated_points']);
        self::assertNotSame($result['components']['icp_fit']['evidence'], $result['components']['relevant_industry']['evidence']);
    }

    public function test_unconfigured_industry_is_excluded_from_score_and_coverage_denominator(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'industry' => ['status' => 'not_configured', 'reason' => 'No target industries are configured.'],
            'geography' => ['status' => 'positive', 'reference' => ['field' => 'location']],
        ], ['industry' => 10, 'geography' => 10]);

        self::assertNull($result['score']);
        self::assertSame(50, $result['evidence_coverage']);
        self::assertSame('NOT_CONFIGURED', $result['components']['industry']['status']);
        self::assertFalse($result['components']['industry']['included_in_denominator']);
        self::assertSame(0, $result['components']['industry']['possible_points']);
    }

    public function test_positive_industry_is_evaluable_and_mismatch_is_negative(): void
    {
        $evaluator = new ScoringRuleEvaluator();
        $positive = $evaluator->score(['industry' => ['status' => 'positive', 'reference' => ['industry' => 'retail']]], ['industry' => 10]);
        $negative = $evaluator->score(['industry' => ['status' => 'negative', 'reference' => ['industry' => 'healthcare']]], ['industry' => 10]);

        self::assertNull($positive['score']);
        self::assertSame('POSITIVE', $positive['components']['industry']['status']);
        self::assertNull($negative['score']);
        self::assertSame('NEGATIVE', $negative['components']['industry']['status']);
        self::assertTrue($negative['components']['industry']['included_in_denominator']);
    }

    public function test_partial_positive_evidence_can_score_but_missing_dimension_is_unknown(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'industry' => ['status' => 'unknown', 'reason' => 'Industry evidence missing.'],
            'geography' => ['status' => 'positive'],
        ], ['industry' => 10, 'geography' => 10]);

        self::assertNull($result['score']);
        self::assertSame(50, $result['evidence_coverage']);
        self::assertSame('UNKNOWN', $result['components']['industry']['status']);
        self::assertFalse($result['components']['industry']['included_in_denominator']);
    }

    public function test_score_breakdown_exposes_explanation_and_no_duplicate_dimensions(): void
    {
        $result = (new ScoringRuleEvaluator())->score([
            'icp_fit' => ['status' => 'positive', 'reference' => ['source' => 'location'], 'reason' => 'Geography match.'],
            'relevant_industry' => ['status' => 'not_configured', 'reason' => 'No target industries.'],
        ], ['icp_fit' => 10, 'relevant_industry' => 10]);

        self::assertNull($result['score']);
        self::assertSame('icp_fit', $result['components']['icp_fit']['dimension']);
        self::assertSame(10, $result['components']['icp_fit']['raw_points']);
        self::assertSame(10, $result['components']['icp_fit']['possible_points']);
        self::assertSame(['source' => 'location'], $result['components']['icp_fit']['evidence_reference']);
        self::assertSame('Geography match.', $result['components']['icp_fit']['reason']);
        self::assertFalse($result['components']['relevant_industry']['included_in_denominator']);
    }
}
