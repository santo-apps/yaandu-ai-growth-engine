<?php

namespace App\WebsiteIntelligence;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The only source of sellable services for Website Intelligence recommendations. */
final class TenantServiceCatalog
{
    /** @return Collection<string, Collection<int, object>> keyed by canonical capability, retaining every mapped commercial service */
    public function recommendationServices(string $tenantId): Collection
    {
        return $this->activeApprovedServices($tenantId)
            ->filter(fn (object $service): bool => in_array((string) $service->canonical_service_key, YaanduServiceTaxonomy::keys(), true))
            ->groupBy('canonical_service_key');
    }

    /** @return Collection<int, object> */
    public function activeApprovedServices(string $tenantId): Collection
    {
        return DB::table('tenant_services')
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereNotNull('approved_by')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', now()))
            ->orderBy('sku')
            ->get(['id', 'sku', 'canonical_service_key', 'name', 'description']);
    }
}
