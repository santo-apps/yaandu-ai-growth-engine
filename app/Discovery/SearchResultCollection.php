<?php

namespace App\Discovery;

final readonly class SearchResultCollection
{
    public function __construct(public array $results) {}
}
