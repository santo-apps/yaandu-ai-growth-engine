<?php

namespace App\LeadDiscovery;

use InvalidArgumentException;

abstract class AbstractOpenLeadSourceAdapter implements SearchLeadSourceAdapterInterface
{
    public function readCsv(string $path, int $maximumRows): array
    {
        throw new InvalidArgumentException('This source adapter does not accept CSV uploads.');
    }

    public function validateConfiguration(array $configuration): void
    {
        if (! config('lead_discovery.live_enabled')) throw new LiveLeadSourceException('SOURCE_DISABLED', 'Live discovery is disabled by application configuration.');
        if (($configuration['source_enabled'] ?? false) !== true) throw new LiveLeadSourceException('SOURCE_DISABLED', 'The tenant source is disabled.');
        if (($configuration['authorization_status'] ?? null) !== 'authorized') throw new LiveLeadSourceException('SOURCE_NOT_AUTHORIZED', 'The tenant has not approved this source for use.');
        if (($configuration['storage_rights_status'] ?? null) !== 'confirmed'
            || ($configuration['storage_rights_confirmed'] ?? false) !== true
            || trim((string) ($configuration['storage_rights_reference'] ?? '')) === '') {
            throw new LiveLeadSourceException('STORAGE_RIGHTS_NOT_CONFIRMED', 'Explicit persistent-storage rights are not confirmed for this source.');
        }
        if (trim((string) ($configuration['license_or_authorization'] ?? '')) === '') throw new LiveLeadSourceException('SOURCE_NOT_AUTHORIZED', 'Record the source license or authorization basis before enabling this source.');
    }

    protected function mapCandidate(string $sourceKey, array $record, array $attributes, array $common = []): array
    {
        $website = $this->firstString($attributes, ['contact:website', 'website', 'official_website', 'website_url']);
        $email = $this->firstString($attributes, ['contact:email', 'email']);
        $phone = $this->firstString($attributes, ['contact:phone', 'phone']);
        $sourceRecordId = (string) ($record['source_record_id'] ?? '');
        $provenanceUrl = $record['source_url'] ?? null;

        return [
            'company_name' => $this->firstString($attributes, ['official_name', 'name', 'brand', 'operator', 'legal_name', 'company_name']) ?? 'Unnamed legal entity',
            'legal_name' => $this->firstString($attributes, ['legal_name', 'official_name', 'company_name']),
            'website' => $website,
            'source_url' => $provenanceUrl,
            'source_reference' => $sourceRecordId,
            'source_record_id' => $sourceRecordId,
            'source_record_type' => (string) ($record['source_record_type'] ?? $sourceKey),
            'country_code' => $this->firstString($attributes, ['country_code', 'country']),
            'region' => $this->firstString($attributes, ['region', 'state', 'registered_state']),
            'city' => $this->firstString($attributes, ['city', 'locality']),
            'postal_code' => $this->firstString($attributes, ['postcode', 'postal_code', 'postalCode']),
            'address' => $this->firstString($attributes, ['address', 'registered_address', 'headquarters_address']),
            'latitude' => $attributes['latitude'] ?? $attributes['lat'] ?? null,
            'longitude' => $attributes['longitude'] ?? $attributes['lon'] ?? null,
            'industry' => $this->firstString($attributes, ['normalized_industry', 'industry']),
            'source_industry' => $this->firstString($attributes, ['source_industry']),
            'source_category' => $this->firstString($attributes, ['source_category', 'category']),
            'geographic_evidence_fields' => is_array($record['geographic_evidence_fields'] ?? null) ? $record['geographic_evidence_fields'] : [],
            'email' => $email, 'phone' => $phone,
            'registration_identifier' => $this->firstString($attributes, ['cin', 'registration_id', 'company_number']),
            'registration_identifier_jurisdiction' => $this->firstString($attributes, ['registration_identifier_jurisdiction']),
            'registration_identifier_registry' => $this->firstString($attributes, ['registration_identifier_registry']),
            'registration_identifier_type' => $this->firstString($attributes, ['registration_identifier_type']),
            'lei' => $this->firstString($attributes, ['lei']), 'wikidata_qid' => $this->firstString($attributes, ['wikidata_qid', 'qid']),
            'osm_object_id' => $this->firstString($attributes, ['osm_object_id']),
            'entity_status' => $this->firstString($attributes, ['entity_status', 'company_status', 'registration_status']),
            'raw_metadata' => $record['raw_metadata'] ?? $attributes,
            'attribution' => [
                'source_key' => $sourceKey, 'license_key' => $common['license_key'] ?? null,
                'license_name' => $common['license_name'] ?? null, 'license_url' => $common['license_url'] ?? null,
                'attribution_text' => $common['attribution_text'] ?? null, 'source_record_id' => $sourceRecordId,
                'source_record_type' => $record['source_record_type'] ?? $sourceKey,
                'source_url' => $provenanceUrl, 'retrieved_at' => now()->toIso8601String(),
            ],
        ];
    }

    protected function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) if (isset($values[$key]) && is_scalar($values[$key]) && trim((string) $values[$key]) !== '') return mb_substr(trim((string) $values[$key]), 0, 2000);
        return null;
    }
}
