<?php

namespace App\AI\Providers;

use App\AI\AIRequest;
use App\AI\AIResponse;
use RuntimeException;

final class GeminiProvider extends AbstractJsonProvider
{
    public function providerKey(): string { return 'gemini'; }
    public function capabilities(): array { return ['text', 'json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $key = config('ai.providers.gemini.key');
        if (! $key) throw new RuntimeException('Gemini credentials are not configured.');
        $url = rtrim(config('ai.providers.gemini.endpoint'), '/').'/models/'.rawurlencode($model).':generateContent';
        $body = $this->post($url, ['x-goog-api-key' => $key], ['systemInstruction' => ['parts' => [['text' => $request->systemInstruction]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $this->prompt($request)]]]],
            'generationConfig' => ['temperature' => $request->temperature, 'maxOutputTokens' => $request->maxOutputTokens, 'responseMimeType' => 'application/json']], $model);
        $blockReason = strtolower((string) ($body['promptFeedback']['blockReason'] ?? ''));
        if (in_array($blockReason, ['safety', 'blocklist', 'prohibited_content', 'recitation'], true)) $this->rejectContentRefusal($model, $blockReason);
        $finishReason = strtolower((string) ($body['candidates'][0]['finishReason'] ?? ''));
        if (in_array($finishReason, ['safety', 'blocklist', 'prohibited_content', 'recitation'], true)) $this->rejectContentRefusal($model, $finishReason);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->decode(is_string($text) ? $text : '', $model,
            $body['usageMetadata']['promptTokenCount'] ?? null, $body['usageMetadata']['candidatesTokenCount'] ?? null);
    }
}
