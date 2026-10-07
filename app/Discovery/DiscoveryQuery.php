<?php

namespace App\Discovery;

final readonly class DiscoveryQuery
{
    public function __construct(public array $criteria = [], public int $limit = 25, public array $seeds = []) {}
}
