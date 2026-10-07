<?php

namespace App\Discovery;

final readonly class SearchQuery
{
    public function __construct(public array $phrases, public int $limit) {}
}
