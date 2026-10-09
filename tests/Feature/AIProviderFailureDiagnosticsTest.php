<?php

namespace Tests\Feature;

use App\AI\AIRequest;
use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\AIResponse;
use App\AI\Providers\AIProviderException;
use App\AI\Providers\OpenAIProvider;
use App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class AIProviderFailureDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.key' => 'sk-test-never-output', 'ai.providers.openai.endpoint' => 'https://api.openai.test/v1', 'ai.retries.attempts' => 1]);
    }

    public function test_http_failures_are_classified_with_safe_provider_diagnostics(): void
    {
        $cases = [
            [400, 'invalid_request_error', 'invalid_json_schema', 'PROVIDER_SCHEMA'],
            [400, 'invalid_request_error', 'invalid_request', 'PROVIDER_REQUEST_INVALID'],
            [401, 'authentication_error', 'invalid_api_key', 'PROVIDER_AUTH'],
            [403, 'permission_error', 'permission_denied', 'PROVIDER_AUTH'],
            [404, 'invalid_request_error', 'model_not_found', 'MODEL_UNAVAILABLE'],
            [429, 'rate_limit_error', 'rate_limit_exceeded', 'PROVIDER_RATE_LIMIT'],
            [429, 'error', 'insufficient_quota', 'PROVIDER_QUOTA'],
            [408, 'error', 'request_timeout', 'PROVIDER_TIMEOUT'],
            [500, 'server_error', 'internal_error', 'PROVIDER_SERVER'],
        ];
        $sequence = Http::fakeSequence('api.openai.test/*');
        foreach ($cases as [$status, $type, $code]) {
            $sequence->push(['error' => [
                'message' => 'Sensitive text sk-test-never-output Return only JSON matching the requested schema.',
                'type' => $type, 'code' => $code,
            ]], $status);
        }

        foreach ($cases as [$status, $type, $code, $expected]) {
            try {
                $this->provider()->generate($this->request(), 'gpt-4.1-mini');
                self::fail("Expected HTTP {$status} to fail.");
            } catch (AIProviderException $error) {
                self::assertSame($status, $error->httpStatus);
                self::assertSame('OPENAI_RESPONSE_RECEIVED', $error->requestOutcome);
                self::assertSame($expected, (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
                self::assertSame('https://api.openai.test/v1/chat/completions', $error->endpoint);
                self::assertSame('gpt-4.1-mini', $error->model);
                self::assertStringNotContainsString('sk-test-never-output', json_encode($error->safeDiagnostics()));
                self::assertStringNotContainsString('Return only JSON', json_encode($error->safeDiagnostics()));
            }
        }
    }

    public function test_connection_timeout_is_reported_as_dispatched_without_response(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected a connection failure.');
        } catch (AIProviderException $error) {
            self::assertSame('REQUEST_DISPATCHED_NO_RESPONSE', $error->requestOutcome);
            self::assertSame('provider_timeout', $error->stage);
            self::assertSame('PROVIDER_TIMEOUT', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    public function test_connection_failure_is_distinct_from_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not connect to host.'));

        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected a connection failure.');
        } catch (AIProviderException $error) {
            self::assertSame('REQUEST_DISPATCHED_NO_RESPONSE', $error->requestOutcome);
            self::assertSame('provider_connection', $error->stage);
            self::assertSame('PROVIDER_CONNECTION', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    public function test_malformed_http_and_model_responses_are_distinguished(): void
    {
        Http::fake(['api.openai.test/*' => Http::response('not-json', 200)]);
        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected malformed HTTP JSON to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('PROVIDER_RESPONSE_PARSE', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
            self::assertSame('OPENAI_RESPONSE_RECEIVED', $error->requestOutcome);
        }

        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => 'not-json']]]], 200)]);
        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected malformed structured model content to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('PROVIDER_RESPONSE_PARSE', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    public function test_success_preserves_structured_data_and_token_usage(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"result":"ok"}']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4]], 200)]);

        $response = $this->provider()->generate($this->request(), 'gpt-4.1-mini');
        self::assertSame(['result' => 'ok'], $response->data);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(4, $response->outputTokens);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.openai.test/v1/chat/completions'
            && ($request['model'] ?? null) === 'gpt-4.1-mini'
            && ($request['max_tokens'] ?? null) === 64
            && ($request['response_format']['type'] ?? null) === 'json_object'
            && isset($request['messages'][0]['content'], $request['messages'][1]['content']));
    }

    public function test_unknown_provider_errors_remain_unknown(): void
    {
        self::assertSame('UNKNOWN', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider(new RuntimeException('unclassified provider issue')));
    }

    public function test_router_schema_failure_records_that_openai_response_was_received(): void
    {
        $provider = new class implements AIProviderInterface {
            public function providerKey(): string { return 'openai'; }
            public function capabilities(): array { return ['text', 'json']; }
            public function generate(AIRequest $request, string $model): AIResponse
            {
                return new AIResponse([], 'openai', $model, 2, 1, 25, 200, 'https://api.openai.test/v1/chat/completions');
            }
        };
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);

        try {
            $router->generate($this->request());
            self::fail('Expected output schema validation to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('response_schema', $error->stage);
            self::assertSame('OPENAI_RESPONSE_RECEIVED', $error->requestOutcome);
            self::assertSame(200, $error->httpStatus);
            self::assertSame('PROVIDER_SCHEMA', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    private function provider(): OpenAIProvider
    {
        return new OpenAIProvider;
    }

    private function request(): AIRequest
    {
        return new AIRequest('website_reasoning', 'Synthetic test prompt.', ['test' => 'synthetic'],
            ['type' => 'object', 'required' => ['result'], 'properties' => ['result' => ['type' => 'string']]],
            maxOutputTokens: 64, temperature: 0);
    }
}
