<?php

namespace App\WebsiteIntelligence;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The only source of sellable services for Website Intelligence recommendations. */
final class TenantServiceCatalog
{
    /** @return Collection<string, object> keyed by the canonical service key */
    public function recommendationServices(string $tenantId): Collection
    {
        return $this->activeApprovedServices($tenantId)
            ->filter(fn (object $service): bool => in_array((string) $service->sku, YaanduServiceTaxonomy::keys(), true))
            ->keyBy('sku');
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
            ->get(['id', 'sku', 'name', 'description']);
    }
}
