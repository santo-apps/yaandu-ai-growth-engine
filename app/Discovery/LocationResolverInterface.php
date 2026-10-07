<?php

namespace App\Discovery;

interface LocationResolverInterface
{
    public function resolve(array $location): ?ResolvedLocation;
}
