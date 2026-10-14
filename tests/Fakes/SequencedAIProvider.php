<?php

namespace Tests\Fakes;

use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;
use RuntimeException;

final class SequencedAIProvider implements AIProviderInterface
{
    /** @var list<AIRequest> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses) {}

    public function providerKey(): string { return 'conversation-test'; }
    public function capabilities(): array { return ['structured_json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $this->requests[] = $request;
        $data = array_shift($this->responses);
        if (! is_array($data)) throw new RuntimeException('No synthetic provider response remains.');

        return new AIResponse($data, $this->providerKey(), $model, 1349, 352);
    }
}
