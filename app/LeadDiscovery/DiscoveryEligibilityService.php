<?php

namespace App\LeadDiscovery;

final class DiscoveryEligibilityService
{
    /** @return array{status:string,reason_code:string,reason:string,confidence:float} */
    public function evaluate(array $candidate, array $campaign, array $source): array
    {
        if (! ($source['enabled'] ?? false) || ($source['authorization_status'] ?? null) !== 'authorized') {
            return $this->result('rejected', 'SOURCE_NOT_AUTHORIZED', 'The selected source is not authorized for this use.', 0.0);
        }
        if (($candidate['duplicate_status'] ?? null) === 'duplicate') {
            return $this->result('rejected', 'DUPLICATE', 'A matching company or candidate already exists in this tenant.', 0.0);
        }
        if (empty($candidate['company_name']) || empty($candidate['normalized_domain'])) {
            return $this->result('rejected', 'IDENTITY_INCOMPLETE', 'A business name and normalized website domain are required.', 0.0);
        }
        $geography = in_array($candidate['geographic_relevance'] ?? null, GeographicRelevanceClassifier::CLASSIFICATIONS, true)
            ? $candidate['geographic_relevance'] : 'location_unknown';
        if ($geography === 'location_mismatch') {
            return $this->result('rejected', 'GEOGRAPHIC_LOCATION_MISMATCH', 'Reliable source location evidence conflicts with the campaign geography.', 0.0);
        }
        if (($candidate['official_domain_status'] ?? null) !== 'verified') {
            return $this->result('needs_review', 'WEBSITE_UNVERIFIED', 'The official website identity has not been verified.', 0.35);
        }
        $hasFineGeographyIntent = trim((string) ($campaign['region'] ?? '')) !== '' || trim((string) ($campaign['city'] ?? '')) !== '';
        if ($geography === 'location_unknown' || ($geography === 'country_match' && $hasFineGeographyIntent)) {
            return $this->result('needs_review', 'GEOGRAPHY_UNCONFIRMED', 'Only partial or no source-backed location evidence is available for the requested geography.', 0.5);
        }
        if (! empty($campaign['industry']) && mb_strtolower(trim((string) ($candidate['industry'] ?? ''))) !== mb_strtolower(trim((string) $campaign['industry']))) {
            return $this->result('needs_review', 'INDUSTRY_UNCONFIRMED', 'Candidate industry does not match the campaign target.', 0.5);
        }
        return $this->result('eligible', 'VERIFIED_TARGET_MATCH', 'Authorized source, verified official website, and target industry are supported; geography matches the campaign intent.', 0.9);
    }

    private function result(string $status, string $code, string $reason, float $confidence): array
    {
        return ['status' => $status, 'reason_code' => $code, 'reason' => $reason, 'confidence' => $confidence];
    }
}
