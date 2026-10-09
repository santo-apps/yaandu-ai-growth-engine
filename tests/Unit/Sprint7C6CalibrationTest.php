<?php

namespace Tests\Unit;

use App\Agents\WebsiteIntelligenceAgent;
use App\LeadScoring\ScoringRuleEvaluator;
use App\WebsiteIntelligence\YaanduServiceTaxonomy;
use PHPUnit\Framework\TestCase;

final class Sprint7C6CalibrationTest extends TestCase
{
    public function test_synthetic_cases_enforce_coverage_and_order_fit_sensibly(): void
    {
        $evaluator = new ScoringRuleEvaluator();
        $strong = array_fill_keys(array_keys(ScoringRuleEvaluator::DEFAULT_RULES), ['status' => 'positive']);
        $weak = array_fill_keys(array_keys(ScoringRuleEvaluator::DEFAULT_RULES), ['status' => 'negative']);
        $mixed = [
            'icp_fit' => ['status' => 'positive'], 'relevant_industry' => ['status' => 'positive'],
            'outdated_website' => ['status' => 'positive'], 'poor_mobile_ux' => ['status' => 'negative'],
        ];
        $industryOnly = ['relevant_industry' => ['status' => 'positive']];
        $unknownIndustryDigitalFit = [
            'icp_fit' => ['status' => 'positive'], 'relevant_industry' => ['status' => 'unknown'],
            'outdated_website' => ['status' => 'positive'], 'poor_lead_capture' => ['status' => 'positive'],
        ];
        $geographyOnly = ['icp_fit' => ['status' => 'positive']];
        $serviceFit = [
            'icp_fit' => ['status' => 'positive'], 'outdated_website' => ['status' => 'positive'],
            'strong_business_fit' => ['status' => 'positive'], 'decision_maker_identified' => ['status' => 'positive'],
        ];
        $conflicting = [
            'icp_fit' => ['status' => 'positive'], 'relevant_industry' => ['status' => 'negative'],
            'outdated_website' => ['status' => 'positive'], 'poor_mobile_ux' => ['status' => 'negative'],
        ];

        $scores = [
            'strong' => $evaluator->score($strong), 'weak' => $evaluator->score($weak),
            'mixed' => $evaluator->score($mixed), 'industry_only' => $evaluator->score($industryOnly),
            'digital_unknown_industry' => $evaluator->score($unknownIndustryDigitalFit),
            'geography_only' => $evaluator->score($geographyOnly), 'service_fit' => $evaluator->score($serviceFit),
            'none' => $evaluator->score([]), 'conflicting' => $evaluator->score($conflicting),
        ];

        self::assertSame(100, $scores['strong']['score']);
        self::assertSame(0, $scores['weak']['score']);
        self::assertLessThan($scores['mixed']['score'], $scores['conflicting']['score']);
        self::assertLessThan($scores['strong']['score'], $scores['mixed']['score']);
        self::assertSame('insufficient_evidence', $scores['industry_only']['evaluation_status']);
        self::assertSame('insufficient_evidence', $scores['geography_only']['evaluation_status']);
        self::assertSame(100, $scores['digital_unknown_industry']['score']);
        self::assertNotNull($scores['service_fit']['score']);
        self::assertNull($scores['none']['score']);
        self::assertSame(30, $scores['industry_only']['minimum_evidence_coverage']);
    }

    public function test_v2_schema_restricts_service_recommendations_to_controlled_taxonomy(): void
    {
        $recommendation = WebsiteIntelligenceAgent::modelSchemaV2()['properties']['service_recommendations']['items'];
        self::assertSame(YaanduServiceTaxonomy::keys(), $recommendation['properties']['service_key']['enum']);
        self::assertContains('website_modernization', YaanduServiceTaxonomy::keys());
        self::assertContains('whatsapp_automation_converiq', YaanduServiceTaxonomy::keys());
        self::assertArrayHasKey('next_actions', WebsiteIntelligenceAgent::modelSchemaV2()['properties']);
    }
}
