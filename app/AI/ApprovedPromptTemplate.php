<?php

namespace App\AI;

final readonly class ApprovedPromptTemplate
{
    public function __construct(public string $id, public int $version, public string $systemInstruction, public string $template) {}
}
