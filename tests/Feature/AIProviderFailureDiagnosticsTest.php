<?php

namespace Tests\Feature;

use App\AI\AIRequest;
use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\AIResponse;
use App\Agents\AgentFailure;
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
        $sequence = Http::fakeSequence('api.openai.test/*')->push('not-json', 200)->push([
            'choices' => [['message' => ['content' => 'not-json']]],
        ], 200);
        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected malformed HTTP JSON to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('PROVIDER_ENVELOPE_PARSE', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
            self::assertSame('OPENAI_RESPONSE_RECEIVED', $error->requestOutcome);
        }

        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected malformed structured model content to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('PROVIDER_RESPONSE_MALFORMED_JSON', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    public function test_markdown_json_fence_and_bom_are_normalized_without_losing_safe_diagnostics(): void
    {
        $content = "\xEF\xBB\xBF```json\n{\"result\":\"ok\"}\n```";
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], 200)]);
        $response = $this->provider()->generate($this->request(), 'gpt-4.1-mini');
        self::assertSame(['result' => 'ok'], $response->data);
        self::assertSame('normalized_json', $response->parseDiagnostics['classification']);
        self::assertSame(['utf8_bom_removed', 'markdown_fence_removed'], $response->parseDiagnostics['normalizations']);
        self::assertSame(strlen($content), $response->parseDiagnostics['response_length']);
        self::assertNotEmpty($response->parseDiagnostics['response_fingerprint']);
        self::assertArrayNotHasKey('raw_output', $response->parseDiagnostics);
    }

    public function test_one_surrounding_prose_wrapper_is_removed_only_when_one_complete_json_value_exists(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => 'Provider result follows:\n{"result":"ok"}\nEnd of response.']]]], 200)]);
        $response = $this->provider()->generate($this->request(), 'gpt-4.1-mini');
        self::assertSame(['result' => 'ok'], $response->data);
        self::assertSame(['surrounding_prose_removed'], $response->parseDiagnostics['normalizations']);
    }

    public function test_empty_malformed_truncated_and_wrong_type_outputs_have_distinct_safe_classifications(): void
    {
        $cases = [
            ['', 'response_empty', 'PROVIDER_RESPONSE_EMPTY'],
            ['{"result":}', 'response_malformed', 'PROVIDER_RESPONSE_MALFORMED_JSON'],
            ['{"result":"ok"', 'response_truncated', 'PROVIDER_RESPONSE_TRUNCATED'],
            ['[]', 'response_wrong_type', 'PROVIDER_RESPONSE_WRONG_TYPE'],
        ];
        $sequence = Http::fakeSequence('api.openai.test/*');
        foreach ($cases as [$content]) $sequence->push(['choices' => [['message' => ['content' => $content]]]], 200);
        foreach ($cases as [$content, $stage, $classification]) {
            try {
                $this->provider()->generate($this->request(), 'gpt-4.1-mini');
                self::fail("Expected {$stage} output to fail.");
            } catch (AIProviderException $error) {
                self::assertSame($stage, $error->stage);
                self::assertSame($classification, (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
                self::assertSame($classification, AgentFailure::from($error, 'synthetic-correlation')->code);
                self::assertSame(strlen($content), $error->safeDiagnostics()['response_length']);
                self::assertArrayNotHasKey('raw_output', $error->safeDiagnostics());
                if ($content !== '') self::assertStringNotContainsString($content, $error->getMessage());
            }
        }
    }

    public function test_openai_refusal_signal_is_classified_without_logging_refusal_text(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['finish_reason' => 'content_filter', 'message' => [
            'content' => null, 'refusal' => 'Synthetic sensitive refusal text.',
        ]]]], 200)]);
        try {
            $this->provider()->generate($this->request(), 'gpt-4.1-mini');
            self::fail('Expected provider refusal to fail safely.');
        } catch (AIProviderException $error) {
            self::assertSame('content_refusal', $error->stage);
            self::assertSame('PROVIDER_CONTENT_REFUSAL', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
            self::assertStringNotContainsString('Synthetic sensitive refusal text.', json_encode($error->safeDiagnostics()));
        }
    }

    public function test_website_reasoning_retries_a_parse_failure_once_and_does_not_retry_schema_failure(): void
    {
        $provider = new class implements AIProviderInterface {
            public int $calls = 0;
            public function providerKey(): string { return 'openai'; }
            public function capabilities(): array { return ['text', 'json']; }
            public function generate(AIRequest $request, string $model): AIResponse
            {
                $this->calls++;
                if ($this->calls === 1) throw new AIProviderException('Malformed JSON.', 'response_malformed', 'OPENAI_RESPONSE_RECEIVED',
                    elapsedMs: 50, inputTokens: 7, outputTokens: 2);
                return new AIResponse(['result' => 'recovered'], 'openai', $model, 11, 3, 75);
            }
        };
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);
        $recovered = $router->generate($this->request());
        self::assertSame(['result' => 'recovered'], $recovered->data);
        self::assertSame(1, $recovered->parseDiagnostics['parse_retry_count']);
        self::assertSame(18, $recovered->inputTokens);
        self::assertSame(5, $recovered->outputTokens);
        self::assertSame(125, $recovered->latencyMs);
        self::assertSame(2, $provider->calls);

        $alwaysMalformed = new class implements AIProviderInterface {
            public int $calls = 0;
            public function providerKey(): string { return 'openai'; }
            public function capabilities(): array { return ['text', 'json']; }
            public function generate(AIRequest $request, string $model): AIResponse
            {
                $this->calls++;
                throw new AIProviderException('Malformed JSON.', 'response_malformed', 'OPENAI_RESPONSE_RECEIVED');
            }
        };
        $retryRouter = new AIModelRouter([$alwaysMalformed], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);
        try { $retryRouter->generate($this->request()); self::fail('Expected the second malformed response to fail.'); }
        catch (AIProviderException $error) {
            self::assertSame('response_malformed', $error->stage);
            self::assertSame(2, $alwaysMalformed->calls, 'A parse failure gets no more than one retry.');
        }

        $badSchemaProvider = new class implements AIProviderInterface {
            public int $calls = 0;
            public function providerKey(): string { return 'openai'; }
            public function capabilities(): array { return ['text', 'json']; }
            public function generate(AIRequest $request, string $model): AIResponse { $this->calls++; return new AIResponse([], 'openai', $model, parseDiagnostics: ['top_level_type' => 'object']); }
        };
        $schemaRouter = new AIModelRouter([$badSchemaProvider], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);
        try { $schemaRouter->generate($this->request()); self::fail('Expected required schema fields to be missing.'); }
        catch (AIProviderException $error) {
            self::assertSame('response_missing_required_fields', $error->stage);
            self::assertSame('PROVIDER_REQUIRED_FIELDS', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
            self::assertSame(1, $badSchemaProvider->calls);
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
                return new AIResponse([], 'openai', $model, 2, 1, 25, 200, 'https://api.openai.test/v1/chat/completions', ['top_level_type' => 'object']);
            }
        };
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);

        try {
            $router->generate($this->request());
            self::fail('Expected output schema validation to fail.');
        } catch (AIProviderException $error) {
            self::assertSame('response_missing_required_fields', $error->stage);
            self::assertSame('OPENAI_RESPONSE_RECEIVED', $error->requestOutcome);
            self::assertSame(200, $error->httpStatus);
            self::assertSame('PROVIDER_REQUIRED_FIELDS', (new WebsiteIntelligenceFailureTaxonomy)->classifyProvider($error));
        }
    }

    public function test_valid_json_schema_mismatch_is_distinct_from_missing_fields(): void
    {
        $provider = new class implements AIProviderInterface {
            public function providerKey(): string { return 'openai'; }
            public function capabilities(): array { return ['text', 'json']; }
            public function generate(AIRequest $request, string $model): AIResponse { return new AIResponse(['result' => ['unexpected' => true]], 'openai', $model); }
        };
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini']]);
        try { $router->generate($this->request()); self::fail('Expected schema mismatch.'); }
        catch (AIProviderException $error) {
            self::assertSame('response_schema', $error->stage);
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
