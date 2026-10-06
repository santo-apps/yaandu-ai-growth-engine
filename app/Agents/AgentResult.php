<?php

namespace App\Agents;

final readonly class AgentResult
{
    public function __construct(public array $data, public string $summary, public array $evidence = []) {}
}
