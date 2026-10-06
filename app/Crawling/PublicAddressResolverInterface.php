<?php

namespace App\Crawling;

interface PublicAddressResolverInterface
{
    /** @return list<string> Public IP addresses for the hostname. */
    public function resolve(string $host): array;
}
