<?php

namespace App\Console\Commands;

use App\AI\AIModelRouter;
use App\AI\AIRequest;
use App\AI\Providers\AIProviderException;
use App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy;
use App\WebsiteIntelligence\WebsiteIntelligenceReadinessChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Throwable;

final class WebsiteIntelligenceSmokeTest extends Command
{
    protected $signature = 'ai:smoke-test-website-reasoning {tenant}';
    protected $description = 'Run a synthetic, non-prospect structured-output smoke test against the explicit tenant website_reasoning route.';

    public function handle(AIModelRouter $router, WebsiteIntelligenceReadinessChecker $readiness, WebsiteIntelligenceFailureTaxonomy $taxonomy): int
    {
        $tenant = (string) $this->argument('tenant');
        $route = DB::table('ai_model_configurations')->where('tenant_id', $tenant)->where('task_key', 'website_reasoning')->first();
        $provider = $route->provider ?? null;
        $model = $route->model ?? null;
        if (! $route || ! $route->enabled || ! in_array($provider, ['openai', 'anthropic', 'gemini'], true)
            || ! filled(config('ai.providers.'.$provider.'.key')) || ! $model) {
            $this->error('Smoke test not run: enabled supported provider route and credential are required. No secret values were inspected or output.');
            return self::FAILURE;
        }

        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['result'], 'properties' => ['result' => ['type' => 'string', 'enum' => ['ok']]]];
        $started = microtime(true);
        // Correlation IDs share the UUID-backed agent_runs/ai_usage_records columns.
        $correlationId = (string) Str::uuid();
        $requestShape = ['method' => 'POST', 'request_keys' => ['model', 'temperature', 'max_tokens', 'response_format', 'messages'],
            'response_format' => 'json_object', 'schema_name' => null, 'schema_top_level_keys' => array_keys($schema),
            'synthetic_input_bytes' => strlen('This is a synthetic connectivity test. Return JSON with result exactly equal to ok. No business or prospect data is supplied.')
                + strlen(json_encode(['synthetic_test' => 'No real company or website data.', 'schema' => $schema], JSON_UNESCAPED_SLASHES))];
        try {
            $response = $router->generate(new AIRequest('website_reasoning',
                'This is a synthetic connectivity test. Return JSON with result exactly equal to ok. No business or prospect data is supplied.',
                ['synthetic_test' => 'No real company or website data.'], $schema, maxOutputTokens: 64, temperature: 0,
                correlationId: $correlationId, tenantId: $tenant));
            $passed = ($response->data['result'] ?? null) === 'ok';
            $pricing = app(\App\AI\ModelPricing::class)->resolve($provider, $model);
            $estimatedCost = $pricing ? app(\App\AI\ModelPricing::class)->estimate($pricing, $response->inputTokens, $response->outputTokens) : null;
            $record = ['status' => $passed ? 'passed' : 'failed', 'provider' => $response->provider, 'model' => $response->model,
                'completed_at' => now()->toIso8601String(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'input_tokens_available' => $response->inputTokens !== null, 'output_tokens_available' => $response->outputTokens !== null,
                'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
                'provider_latency_ms' => $response->latencyMs,
                'request_timeout_seconds' => (int) config('ai.timeouts.request', 45), 'failure_category' => $passed ? null : 'PROVIDER_SCHEMA',
                'correlation_id' => $correlationId, 'stage' => 'response_parsed', 'request_outcome' => $this->responseOutcome($provider),
                'http_status' => $response->httpStatus, 'endpoint' => $response->endpoint ?? $this->endpoint($provider, $model),
                'request_shape' => $requestShape,
                'estimated_cost' => $estimatedCost, 'estimated_cost_currency' => $estimatedCost === null ? null : $pricing['currency'],
                'pricing_effective_date' => $estimatedCost === null ? null : $pricing['effective_from'],
                'pricing_version' => $estimatedCost === null ? null : $pricing['version'],
                'cost_status' => $estimatedCost === null ? 'unavailable' : 'estimated'];
        } catch (Throwable $error) {
            $record = ['status' => 'failed', 'provider' => $provider, 'model' => $model, 'completed_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'input_tokens_available' => false, 'output_tokens_available' => false,
                'input_tokens' => null, 'output_tokens' => null,
                'request_timeout_seconds' => (int) config('ai.timeouts.request', 45), 'failure_category' => $taxonomy->classifyProvider($error),
                'correlation_id' => $correlationId,
                'request_shape' => $requestShape, 'diagnostics' => $this->diagnostics($error, $model, $started),
            ];
        }
        Cache::put($readiness->smokeKey($tenant), $record, now()->addHours(24));
        $this->line(json_encode($record, JSON_UNESCAPED_SLASHES));
        return $record['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    private function diagnostics(Throwable $error, string $model, float $started): array
    {
        if ($error instanceof AIProviderException) return $error->safeDiagnostics();

        $isUsagePersistence = $error instanceof QueryException;
        return [
            'stage' => $isUsagePersistence ? 'usage_reservation' : 'pre_provider_or_unclassified',
            'request_outcome' => 'REQUEST_NOT_DISPATCHED', 'http_status' => null,
            'exception_class' => get_class($error),
            'provider_error_type' => null, 'provider_error_code' => null,
            'safe_message' => $isUsagePersistence ? 'AI usage reservation failed before provider dispatch.' : 'Provider request did not complete; details withheld.',
            'endpoint' => null, 'model' => $model, 'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
        ];
    }

    private function endpoint(string $provider, string $model): ?string
    {
        $base = config('ai.providers.'.$provider.'.endpoint');
        if (! is_string($base) || $base === '') return null;
        $path = match ($provider) {
            'openai' => '/chat/completions',
            'anthropic' => '/messages',
            'gemini' => '/models/'.rawurlencode($model).':generateContent',
            default => '',
        };
        return rtrim($base, '/').$path;
    }

    private function responseOutcome(string $provider): string
    {
        return $provider === 'openai' ? 'OPENAI_RESPONSE_RECEIVED' : 'PROVIDER_RESPONSE_RECEIVED';
    }

}
