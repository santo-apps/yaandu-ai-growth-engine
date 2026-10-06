<?php

namespace Tests\Feature;

use App\AI\AIRequest;
use App\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AIProviderRetryTest extends TestCase
{
    public function test_provider_retries_transient_responses_and_returns_structured_json(): void
    {
        config(['ai.providers.openai.key' => 'test-key', 'ai.providers.openai.endpoint' => 'https://api.openai.test/v1', 'ai.retries.attempts' => 2]);
        Http::fakeSequence()->push(['error' => ['message' => 'temporarily busy']], 429)
            ->push(['choices' => [['message' => ['content' => '{"summary":"verified"}']]]], 200);

        $response = (new OpenAIProvider)->generate(new AIRequest(
            task: 'website_reasoning', systemInstruction: 'Return data.', evidence: [],
            outputSchema: ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]],
        ), 'test-model');

        self::assertSame(['summary' => 'verified'], $response->data);
        self::assertSame('openai', $response->provider);
        Http::assertSentCount(2);
    }

    public function test_provider_does_not_retry_permanent_client_errors(): void
    {
        config(['ai.providers.openai.key' => 'test-key', 'ai.providers.openai.endpoint' => 'https://api.openai.test/v1', 'ai.retries.attempts' => 3]);
        Http::fake(['api.openai.test/*' => Http::response(['error' => 'invalid request'], 400)]);

        try {
            (new OpenAIProvider)->generate(new AIRequest('task', 'Return data.', [], ['type' => 'object']), 'test-model');
            self::fail('Expected permanent provider errors to be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertSame('AI provider request failed with HTTP 400', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }
}
