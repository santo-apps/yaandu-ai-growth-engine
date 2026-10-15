<?php

namespace App\LeadDiscovery;

final readonly class LeadSourceCapabilities
{
    public function __construct(
        public string $sourceKey,
        public string $sourceType,
        public bool $supportsLiveQuery,
        public bool $supportsBulkDownload,
        public bool $supportsPagination,
        public bool $supportsDeltaSync,
        public bool $supportsCountryFilter,
        public bool $supportsRegionFilter,
        public bool $supportsCityFilter,
        public bool $supportsIndustryFilter,
        public bool $supportsKeywordFilter,
        public bool $globalCoverage,
        public array $supportedCountries,
        public array $provides,
        public bool $commercialUseAllowed,
        public bool $persistentStorageAllowed,
        public bool $attributionRequired,
        public bool $shareAlikeRequired,
        public string $licenseName,
        public ?string $licenseUrl,
        public ?string $attributionText,
        public int $defaultRateLimit,
        public string $recommendedSyncMode,
        public string $governanceClass,
        public ?string $restrictionReason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey, 'source_type' => $this->sourceType,
            'supports_live_query' => $this->supportsLiveQuery, 'supports_bulk_download' => $this->supportsBulkDownload,
            'supports_pagination' => $this->supportsPagination, 'supports_delta_sync' => $this->supportsDeltaSync,
            'supports_country_filter' => $this->supportsCountryFilter, 'supports_region_filter' => $this->supportsRegionFilter,
            'supports_city_filter' => $this->supportsCityFilter, 'supports_industry_filter' => $this->supportsIndustryFilter,
            'supports_keyword_filter' => $this->supportsKeywordFilter, 'global_coverage' => $this->globalCoverage,
            'supported_countries' => $this->supportedCountries, 'provides' => $this->provides,
            'commercial_use_allowed' => $this->commercialUseAllowed, 'persistent_storage_allowed' => $this->persistentStorageAllowed,
            'attribution_required' => $this->attributionRequired, 'share_alike_required' => $this->shareAlikeRequired,
            'license_name' => $this->licenseName, 'license_url' => $this->licenseUrl, 'attribution_text' => $this->attributionText,
            'default_rate_limit' => $this->defaultRateLimit, 'recommended_sync_mode' => $this->recommendedSyncMode,
            'governance_class' => $this->governanceClass, 'selectable' => $this->governanceClass !== 'PROHIBITED_FOR_BULK_STORAGE',
            'restriction_reason' => $this->restrictionReason,
        ];
    }
}
