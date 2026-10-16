<?php

namespace App\LeadDiscovery;

final class LeadDiscoveryCountryCatalog
{
    public function all(): array { return config('lead_discovery.countries', []); }

    public function normalize(?string $country): ?string
    {
        if ($country === null || trim($country) === '') return null;
        $code = strtoupper(trim($country));
        if (preg_match('/^[A-Z]{2}$/', $code) === 1 && isset($this->all()[$code])) return $code;

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $country) ?? $country));
        foreach ($this->all() as $countryCode => $label) {
            if (mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $label) ?? (string) $label)) === $normalized) return (string) $countryCode;
        }

        return match ($normalized) {
            'uae', 'united arab emirates' => 'AE',
            default => null,
        };
    }
}
