<?php

namespace Tests\Unit;

use App\Sales\QualificationScorer;
use App\Sales\SalesPolicy;
use PHPUnit\Framework\TestCase;

final class SalesPolicyTest extends TestCase
{
    public function test_qualification_is_deterministic_and_unknown_is_zero(): void
    {
        $scorer = new QualificationScorer();
        self::assertSame(0, $scorer->score([])['score']);
        self::assertSame(50, $scorer->score(['NEED'=>'STRONG','FIT'=>'STRONG'])['score']);
        self::assertSame(100, $scorer->score(array_fill_keys(QualificationScorer::DIMENSIONS,'STRONG'))['score']);
    }

    public function test_stage_transitions_and_risk_policy_are_application_controlled(): void
    {
        $policy = new SalesPolicy();
        self::assertTrue($policy->transitionAllowed('DISCOVERY','QUALIFIED'));
        self::assertFalse($policy->transitionAllowed('NEW','PROPOSAL_READY'));
        self::assertSame('HIGH',$policy->risk('PRICING_REQUEST'));
        self::assertTrue($policy->requiresHandoff('MEETING_REQUEST',0.99));
        self::assertTrue($policy->requiresHandoff('GENERAL_QUESTION',0.4));
    }
}
