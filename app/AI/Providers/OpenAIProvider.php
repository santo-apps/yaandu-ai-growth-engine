<?php

namespace App\AI\Providers;

use App\AI\AIRequest;
use App\AI\AIResponse;
use RuntimeException;

final class OpenAIProvider extends AbstractJsonProvider
{
    public function providerKey(): string { return 'openai'; }
    public function capabilities(): array { return ['text', 'json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $key = config('ai.providers.openai.key');
        if (! $key) throw new RuntimeException('OpenAI credentials are not configured.');
        $body = $this->post(rtrim(config('ai.providers.openai.endpoint'), '/').'/chat/completions',
            ['Authorization' => 'Bearer '.$key], [
                'model' => $model, 'temperature' => $request->temperature, 'max_tokens' => $request->maxOutputTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $request->systemInstruction], ['role' => 'user', 'content' => $this->prompt($request)]],
            ], $model);
        $message = $body['choices'][0]['message'] ?? [];
        $finishReason = $body['choices'][0]['finish_reason'] ?? null;
        if (is_array($message) && is_string($message['refusal'] ?? null) && trim($message['refusal']) !== '') $this->rejectContentRefusal($model, 'refusal');
        if ($finishReason === 'content_filter') $this->rejectContentRefusal($model, 'content_filter');
        return $this->decode(is_string($message['content'] ?? null) ? $message['content'] : '', $model, $body['usage']['prompt_tokens'] ?? null, $body['usage']['completion_tokens'] ?? null);
    }
}
