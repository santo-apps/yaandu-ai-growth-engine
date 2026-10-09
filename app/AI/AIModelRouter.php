<?php

namespace App\AI;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AIModelRouter
{
    /** @var array<string, AIProviderInterface> */
    private array $providers = [];
    private readonly JsonSchemaValidator $schemaValidator;
    private readonly ModelPricing $modelPricing;

    public function __construct(iterable $providers, private readonly array $taskConfigurations)
    {
        $this->schemaValidator = new JsonSchemaValidator();
        $this->modelPricing = new ModelPricing();
        foreach ($providers as $provider) {
            $this->providers[$provider->providerKey()] = $provider;
        }
    }

    public function generate(AIRequest $request): AIResponse
    {
        $configuration = $this->taskConfigurations[$request->task] ?? null;
        if ($request->tenantId) {
            $tenantConfiguration = DB::table('ai_model_configurations')->where('tenant_id', $request->tenantId)
                ->where('task_key', $request->task)->first();
        } else {
            $tenantConfiguration = null;
        }
        if ($tenantConfiguration) {
            if (! $tenantConfiguration->enabled) throw new RuntimeException("AI task [{$request->task}] is disabled for this tenant.");
            $parameters = is_array($tenantConfiguration->parameters)
                ? $tenantConfiguration->parameters
                : (json_decode($tenantConfiguration->parameters ?? '{}', true) ?: []);
            $configuration = ['provider' => $tenantConfiguration->provider, 'model' => $tenantConfiguration->model, 'parameters' => $parameters];
        }
        if (! is_array($configuration) || empty($configuration['provider']) || empty($configuration['model'])) {
            throw new RuntimeException("No AI model configured for task [{$request->task}].");
        }

        $provider = $this->providers[$configuration['provider']] ?? null;
        if (! $provider) {
            throw new RuntimeException("Configured AI provider [{$configuration['provider']}] is unavailable.");
        }

        $parameters = $configuration['parameters'] ?? [];
        $effectiveRequest = new AIRequest(
            task: $request->task,
            systemInstruction: $request->systemInstruction,
            evidence: $request->evidence,
            outputSchema: $request->outputSchema,
            maxOutputTokens: min(8192, max(1, (int) ($parameters['max_output_tokens'] ?? $request->maxOutputTokens))),
            temperature: min(2, max(0, (float) ($parameters['temperature'] ?? $request->temperature))),
            correlationId: $request->correlationId,
            tenantId: $request->tenantId,
        );
        $usageId = $this->reserveUsage($effectiveRequest, $configuration['provider'], $configuration['model']);
        try {
            $response = $provider->generate($effectiveRequest, $configuration['model']);
            try {
                $this->schemaValidator->validate($response->data, $request->outputSchema);
            } catch (\Throwable $error) {
                $outcome = $response->provider === 'openai' ? 'OPENAI_RESPONSE_RECEIVED' : 'PROVIDER_RESPONSE_RECEIVED';
                $safeMessage = 'AI provider response did not satisfy the required output schema.';
                if (preg_match('/missing required field \[([a-zA-Z0-9_.-]{1,80})\]/', $error->getMessage(), $missingField)) {
                    $safeMessage = 'AI provider response is missing required field ['.$missingField[1].'].';
                }
                throw new Providers\AIProviderException($safeMessage, 'response_schema', $outcome,
                    httpStatus: $response->httpStatus, endpoint: $response->endpoint, model: $response->model,
                    elapsedMs: $response->latencyMs, previous: $error);
            }
            try {
                $this->completeUsage($usageId, $request, $response);
            } catch (\Throwable $error) {
                $outcome = $response->provider === 'openai' ? 'OPENAI_RESPONSE_RECEIVED' : 'PROVIDER_RESPONSE_RECEIVED';
                throw new Providers\AIProviderException('AI usage metadata could not be persisted after the provider response.', 'usage_persistence', $outcome,
                    httpStatus: $response->httpStatus, endpoint: $response->endpoint, model: $response->model,
                    elapsedMs: $response->latencyMs, previous: $error);
            }
            return $response;
        } catch (\Throwable $error) {
            if ($usageId) DB::table('ai_usage_records')->where('id', $usageId)->update(['status' => 'FAILED']);
            throw $error;
        }
    }

    private function reserveUsage(AIRequest $request, string $provider, string $model): ?string
    {
        if (! $request->tenantId || ! DB::getSchemaBuilder()->hasTable('tenant_automation_settings')
            || ! DB::table('tenants')->where('id', $request->tenantId)->exists()) return null;
        if (! DB::table('tenants')->where('id', $request->tenantId)->where('status', 'active')->exists()) throw new RuntimeException('AI execution is unavailable for this tenant.');
        $usageId = (string) Str::uuid();
        DB::transaction(function () use ($request, $provider, $model, $usageId): void {
            $settings = DB::table('tenant_automation_settings')->where('tenant_id', $request->tenantId)->lockForUpdate()->first();
            $today = DB::table('ai_usage_records')->where('tenant_id', $request->tenantId)->whereDate('created_at', today());
            $calls = (clone $today)->count();
            $tokens = (int) (clone $today)->sum(DB::raw('COALESCE(input_tokens,0) + COALESCE(output_tokens,0) + COALESCE(reserved_tokens,0)'));
            $callLimit = $this->effectiveLimit($settings?->daily_ai_call_limit, config('ai.daily_call_limit', 0));
            if ($callLimit > 0 && $calls >= $callLimit) throw new RuntimeException('Tenant daily AI call budget reached.');
            $reserve = max(1, $request->maxOutputTokens);
            $tokenLimit = $this->effectiveLimit($settings?->daily_token_limit, config('ai.daily_token_limit', 0));
            if ($tokenLimit > 0 && $tokens + $reserve > $tokenLimit) throw new RuntimeException('Tenant daily AI token budget would be exceeded.');
            $pricing = $this->modelPricing->resolve($provider, $model);
            if ($settings?->monthly_estimated_spend_limit !== null) {
                if (! is_array($pricing) || ! isset($pricing['input'], $pricing['output'])) throw new RuntimeException('Monthly spend limit is configured but verified model pricing is unavailable; AI execution is paused.');
                $month = DB::table('ai_usage_records')->where('tenant_id', $request->tenantId)->where('created_at', '>=', now()->startOfMonth());
                $unknownCost = (clone $month)->whereNull('estimated_cost')->exists();
                $spent = (float) (clone $month)->sum('estimated_cost');
                if ($unknownCost || $spent >= (float) $settings->monthly_estimated_spend_limit) throw new RuntimeException('Tenant monthly estimated AI spend budget reached or cannot be safely evaluated.');
            }
            $run = $request->correlationId ? DB::table('agent_runs')->where('tenant_id', $request->tenantId)->where('correlation_id', $request->correlationId)->latest('created_at')->first() : null;
            $workflow = $run ? DB::table('workflow_events')->where('tenant_id', $request->tenantId)->where('agent_run_id', $run->id)->value('workflow_id') : null;
            if ($workflow) {
                $state = DB::table('acquisition_workflows')->where('tenant_id', $request->tenantId)->where('id', $workflow)->first(['status','current_stage']);
                if (in_array($state->status ?? null, ['PAUSED','CANCELLED','COMPLETED'], true) || ($state->current_stage ?? null) === 'HUMAN_HANDOFF') throw new RuntimeException('AI execution is paused for this workflow.');
            }
            DB::table('ai_usage_records')->insert(['id' => $usageId, 'tenant_id' => $request->tenantId, 'agent_key' => $run->agent_key ?? null,
                'task_key' => $request->task, 'provider' => $provider, 'model' => $model, 'reserved_tokens' => $reserve, 'status' => 'RESERVED',
                'agent_run_id' => $run->id ?? null, 'workflow_id' => $workflow, 'correlation_id' => $request->correlationId,
                'idempotency_key' => $usageId, 'created_at' => now()]);
        });
        return $usageId;
    }

    private function completeUsage(?string $usageId, AIRequest $request, AIResponse $response): void
    {
        if (! $usageId) return;
        $pricing = $this->modelPricing->resolve($response->provider, $response->model);
        $cost = $pricing ? $this->modelPricing->estimate($pricing, $response->inputTokens, $response->outputTokens) : null;
        DB::table('ai_usage_records')->where('id', $usageId)->where('tenant_id', $request->tenantId)->update([
            'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens, 'provider_latency_ms' => $response->latencyMs,
            'reserved_tokens' => 0, 'estimated_cost' => $cost, 'estimated_cost_currency' => $cost === null ? null : $pricing['currency'],
            'pricing_effective_date' => $cost === null ? null : $pricing['effective_from'], 'pricing_version' => $cost === null ? null : $pricing['version'],
            'provider' => $response->provider, 'model' => $response->model, 'status' => 'COMPLETED']);
        $usage = DB::table('ai_usage_records')->where('id', $usageId)->where('tenant_id', $request->tenantId)->first();
        if (! $usage?->workflow_id) return;
        $settings = DB::table('tenant_automation_settings')->where('tenant_id', $request->tenantId)->first();
        $daily = DB::table('ai_usage_records')->where('tenant_id', $request->tenantId)->whereDate('created_at', today());
        $callLimit = $this->effectiveLimit($settings?->daily_ai_call_limit, config('ai.daily_call_limit', 0));
        $callsExceeded = $callLimit > 0 && (clone $daily)->count() > $callLimit;
        $tokenTotal = (int) (clone $daily)->sum(DB::raw('COALESCE(input_tokens,0) + COALESCE(output_tokens,0) + COALESCE(reserved_tokens,0)'));
        $tokenLimit = $this->effectiveLimit($settings?->daily_token_limit, config('ai.daily_token_limit', 0));
        $tokensExceeded = $tokenLimit > 0 && $tokenTotal > $tokenLimit;
        $month = DB::table('ai_usage_records')->where('tenant_id', $request->tenantId)->where('created_at', '>=', now()->startOfMonth());
        $spendExceeded = $settings?->monthly_estimated_spend_limit !== null && $cost !== null && (float) (clone $month)->sum('estimated_cost') > (float) $settings->monthly_estimated_spend_limit;
        if ($callsExceeded || $tokensExceeded || $spendExceeded) {
            try { app(\App\Orchestration\WorkflowService::class)->append($request->tenantId, $usage->workflow_id, 'ai_budget_exceeded', 'policy', ['reason_code' => 'tenant_ai_budget'], 'ai-budget:'.$usageId); }
            catch (\Throwable) {}
        }
    }

    private function effectiveLimit(mixed $tenantLimit, mixed $applicationLimit): int
    {
        $tenant = max(0, (int) $tenantLimit);
        $application = max(0, (int) $applicationLimit);
        if ($tenant > 0 && $application > 0) return min($tenant, $application);
        return max($tenant, $application);
    }

}
