<?php

namespace App\AI\Providers;

use App\AI\AIRequest;
use App\AI\AIResponse;
use RuntimeException;

final class AnthropicProvider extends AbstractJsonProvider
{
    public function providerKey(): string { return 'anthropic'; }
    public function capabilities(): array { return ['text', 'json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $key = config('ai.providers.anthropic.key');
        if (! $key) throw new RuntimeException('Anthropic credentials are not configured.');
        $body = $this->post(rtrim(config('ai.providers.anthropic.endpoint'), '/').'/messages', [
            'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
        ], ['model' => $model, 'max_tokens' => $request->maxOutputTokens, 'temperature' => $request->temperature,
            'system' => $request->systemInstruction, 'messages' => [['role' => 'user', 'content' => $this->prompt($request)]]], $model);
        return $this->decode($body['content'][0]['text'] ?? '', $model, $body['usage']['input_tokens'] ?? null, $body['usage']['output_tokens'] ?? null);
    }
}
