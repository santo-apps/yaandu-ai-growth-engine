<?php

namespace App\AI;

use Illuminate\Support\Carbon;
use Throwable;

final class ModelPricing
{
    /** @return array{input:float,output:float,currency:string,effective_from:string,version:string}|null */
    public function resolve(string $provider, string $model): ?array
    {
        $pricing = config('ai.model_pricing_per_1k', [])[$provider][$model] ?? null;
        if (! is_array($pricing) || ! is_numeric($pricing['input'] ?? null) || ! is_numeric($pricing['output'] ?? null)
            || (float) $pricing['input'] < 0 || (float) $pricing['output'] < 0
            || ! is_string($pricing['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/', $pricing['currency'])
            || ! is_string($pricing['version'] ?? null) || trim($pricing['version']) === '') return null;

        if (! is_string($pricing['effective_from'] ?? null) || trim($pricing['effective_from']) === '') return null;
        try { $effectiveFrom = Carbon::parse($pricing['effective_from'])->toDateString(); }
        catch (Throwable) { return null; }
        if ($effectiveFrom > now()->toDateString()) return null;

        return ['input' => (float) $pricing['input'], 'output' => (float) $pricing['output'],
            'currency' => $pricing['currency'], 'effective_from' => $effectiveFrom, 'version' => $pricing['version']];
    }

    public function estimate(array $pricing, ?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($inputTokens === null || $outputTokens === null || $inputTokens < 0 || $outputTokens < 0) return null;
        return round((($inputTokens * $pricing['input']) + ($outputTokens * $pricing['output'])) / 1000, 6);
    }
}
