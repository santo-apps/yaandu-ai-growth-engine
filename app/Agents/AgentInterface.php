<?php

namespace App\Agents;

interface AgentInterface
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function outputSchema(): array;
    public function tools(): array;
    public function execute(AgentContext $context, array $input): AgentResult;
}
