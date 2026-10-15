<?php

namespace App\LeadDiscovery;

final class LeadDiscoveryCountryCatalog
{
    public function all(): array { return config('lead_discovery.countries', []); }

    public function normalize(?string $country): ?string
    {
        if ($country === null || trim($country) === '') return null;
        $code = strtoupper(trim($country));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 && isset($this->all()[$code]) ? $code : null;
    }
}
