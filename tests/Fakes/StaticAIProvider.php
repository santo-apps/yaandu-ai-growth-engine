<?php

namespace Tests\Fakes;

use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;

final class StaticAIProvider implements AIProviderInterface
{
    public array $requests = [];

    public function __construct(private readonly array $data) {}
    public function providerKey(): string { return 'conversation-test'; }
    public function capabilities(): array { return ['structured_json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $this->requests[] = $request;

        return new AIResponse($this->data, $this->providerKey(), $model);
    }
}
