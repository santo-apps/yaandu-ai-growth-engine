<?php

namespace App\LeadDiscovery;

final class LeadDiscoveryIndustryTaxonomy
{
    public function all(): array
    {
        return config('lead_discovery.industries', []);
    }

    public function normalize(?string $industry): ?string
    {
        if ($industry === null || trim($industry) === '') return null;
        $key = $this->keyFor($industry);
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    public function keyFor(?string $industry): ?string
    {
        if ($industry === null || trim($industry) === '') return null;
        $normalized = mb_strtolower(trim($industry));
        $aliases = ['software' => 'it_software', 'it' => 'it_software', 'health care' => 'healthcare', 'retail' => 'ecommerce_retail', 'textiles' => 'textiles_apparel'];
        if (isset($aliases[$normalized])) return $aliases[$normalized];
        foreach ($this->all() as $key => $label) {
            if (mb_strtolower((string) $key) === $normalized || mb_strtolower((string) $label) === $normalized) return (string) $key;
        }

        return null;
    }
}
