<?php

namespace App\Discovery;

final readonly class ResolvedLocation
{
    public function __construct(public string $label, public array $bbox, public ?string $country = null, public ?string $region = null) {}
}
