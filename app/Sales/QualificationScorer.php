<?php

namespace App\Sales;

final class QualificationScorer
{
    public const DIMENSIONS = ['NEED', 'FIT', 'AUTHORITY', 'TIMELINE', 'BUDGET'];
    private const LEVELS = ['UNKNOWN' => 0.0, 'WEAK' => 0.33, 'MODERATE' => 0.67, 'STRONG' => 1.0];

    public function score(array $qualification, array $weights = [], array $thresholds = []): array
    {
        $defaults = ['NEED' => 25, 'FIT' => 25, 'AUTHORITY' => 20, 'TIMELINE' => 15, 'BUDGET' => 15];
        $weights = array_intersect_key(array_merge($defaults, $weights), $defaults);
        $sum = array_sum($weights) ?: 100;
        $components = [];
        foreach (self::DIMENSIONS as $dimension) {
            $level = strtoupper((string) ($qualification[$dimension] ?? 'UNKNOWN'));
            $components[$dimension] = [
                'level' => isset(self::LEVELS[$level]) ? $level : 'UNKNOWN',
                'weight' => (int) $weights[$dimension],
                'score' => round(((self::LEVELS[$level] ?? 0) * $weights[$dimension] / $sum) * 100, 2),
            ];
        }
        $score = (int) round(array_sum(array_column($components, 'score')));
        $limits = array_merge(['DEVELOPING' => 40, 'QUALIFIED' => 60, 'HIGH_PRIORITY' => 80], $thresholds);
        $level = $score >= $limits['HIGH_PRIORITY'] ? 'HIGH_PRIORITY' : ($score >= $limits['QUALIFIED'] ? 'QUALIFIED' : ($score >= $limits['DEVELOPING'] ? 'DEVELOPING' : 'LOW'));
        return ['score' => min(100, max(0, $score)), 'level' => $level, 'components' => $components];
    }
}
