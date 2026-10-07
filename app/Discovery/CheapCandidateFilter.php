<?php

namespace App\Discovery;

final class CheapCandidateFilter
{
    public function evaluate(object $candidate): array
    {
        $signals = [
            'reachable_website' => $candidate->verification_state === 'verified',
            'https' => (bool) ($candidate->uses_https ?? false),
            'mobile_viewport' => (bool) ($candidate->has_mobile_viewport ?? false),
            'page_title' => trim((string) ($candidate->page_title ?? '')) !== '',
            'recommended_service' => trim((string) ($candidate->recommended_service ?? '')) !== '',
            'fast_response' => $candidate->response_time_ms !== null && (int) $candidate->response_time_ms <= 2000,
        ];
        $score = count(array_filter($signals));
        $eligible = $signals['reachable_website'] && $score >= max(1, (int) config('discovery.analysis_min_cheap_score', 2));
        $reasons = array_keys(array_filter($signals));
        return ['eligible' => $eligible, 'score' => $score,
            'reason' => $eligible ? 'Deterministic signals: '.implode(', ', $reasons).'.' : 'Does not meet the configured deterministic pre-analysis threshold.'];
    }
}
