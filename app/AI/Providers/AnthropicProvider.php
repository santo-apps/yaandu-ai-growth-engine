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
        if (($body['stop_reason'] ?? null) === 'refusal') $this->rejectContentRefusal($model, 'refusal');
        $content = is_array($body['content'] ?? null) ? $body['content'] : [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'refusal') $this->rejectContentRefusal($model, 'refusal');
        }
        $text = $content[0]['text'] ?? '';
        return $this->decode(is_string($text) ? $text : '', $model, $body['usage']['input_tokens'] ?? null, $body['usage']['output_tokens'] ?? null);
    }
}
