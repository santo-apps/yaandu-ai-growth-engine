<?php

namespace App\WebsiteResolution;

interface WebIndexIngestionSourceInterface
{
    public function name(): string;

    /** @return iterable<array<string,mixed>> */
    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable;

    /** @return array<string,int> */
    public function metrics(): array;
}
