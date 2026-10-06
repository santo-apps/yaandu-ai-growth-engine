<?php

namespace App\Proposals;

interface ProposalDocumentRendererInterface
{
    public function render(array $proposal): string;
}
