<?php

namespace App\Discovery;

interface DiscoverySourceInterface
{
    public function name(): string;
    public function capabilities(): array;
    /** @return list<array{name?:string,website:string,country?:string,city?:string,industry?:string,source_reference?:string}> */
    public function search(DiscoveryQuery $query): array;
}
