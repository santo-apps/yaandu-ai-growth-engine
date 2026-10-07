<?php

namespace App\Discovery;

interface DiscoverySourceInterface
{
    public function name(): string;
    public function capabilities(): array;
    /** @return list<array{name?:string,website?:string|null,country?:string,city?:string,industry?:string,source_reference?:string,source_metadata?:array}> */
    public function search(DiscoveryQuery $query): array;
}
