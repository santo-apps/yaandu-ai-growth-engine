<?php

namespace App\AI;

interface AIProviderInterface
{
    public function providerKey(): string;

    public function capabilities(): array;

    public function generate(AIRequest $request, string $model): AIResponse;
}
