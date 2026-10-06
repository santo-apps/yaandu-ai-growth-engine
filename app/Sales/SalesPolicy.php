<?php

namespace App\Sales;

final class SalesPolicy
{
    public const STAGES = ['NEW', 'ENGAGED', 'DISCOVERY', 'QUALIFIED', 'MEETING_READY', 'PROPOSAL_READY', 'CLOSED', 'NOT_QUALIFIED'];
    public const INTENTS = ['GENERAL_QUESTION', 'SERVICE_QUESTION', 'TECHNICAL_QUESTION', 'INTERESTED', 'OBJECTION', 'PRICING_REQUEST', 'DISCOUNT_REQUEST', 'MEETING_REQUEST', 'PROPOSAL_REQUEST', 'TIMELINE_QUESTION', 'CAPABILITY_QUESTION', 'CASE_STUDY_REQUEST', 'NOT_INTERESTED', 'UNSUBSCRIBE', 'WRONG_CONTACT', 'HUMAN_REQUEST', 'UNCLEAR'];
    private const TRANSITIONS = ['NEW' => ['ENGAGED', 'DISCOVERY', 'QUALIFIED', 'CLOSED', 'NOT_QUALIFIED'], 'ENGAGED' => ['DISCOVERY', 'QUALIFIED', 'CLOSED', 'NOT_QUALIFIED'], 'DISCOVERY' => ['QUALIFIED', 'MEETING_READY', 'CLOSED', 'NOT_QUALIFIED'], 'QUALIFIED' => ['MEETING_READY', 'PROPOSAL_READY', 'CLOSED'], 'MEETING_READY' => ['PROPOSAL_READY', 'CLOSED'], 'PROPOSAL_READY' => ['CLOSED'], 'CLOSED' => [], 'NOT_QUALIFIED' => []];

    public function transitionAllowed(string $from, string $to): bool { return ($to === 'NOT_QUALIFIED' && ! in_array($from, ['CLOSED','NOT_QUALIFIED'], true)) || in_array($to, self::TRANSITIONS[$from] ?? [], true); }
    public function risk(string $intent): string
    {
        if (in_array($intent, ['PRICING_REQUEST', 'DISCOUNT_REQUEST', 'MEETING_REQUEST', 'PROPOSAL_REQUEST', 'HUMAN_REQUEST', 'UNCLEAR'], true)) return 'HIGH';
        if (in_array($intent, ['TECHNICAL_QUESTION', 'OBJECTION', 'TIMELINE_QUESTION', 'INTERESTED'], true)) return 'MEDIUM';
        return 'LOW';
    }
    public function requiresHandoff(string $intent, float $confidence, bool $unknownReferences = false): bool
    {
        return $this->risk($intent) !== 'LOW' || $confidence < 0.65 || $unknownReferences
            || in_array($intent, ['UNSUBSCRIBE', 'NOT_INTERESTED', 'WRONG_CONTACT'], true);
    }
}
