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
        if (($candidate['official_domain_status'] ?? null) !== 'verified') {
            return $this->result('needs_review', 'WEBSITE_UNVERIFIED', 'The official website identity has not been verified.', 0.35);
        }
        if (! empty($campaign['country_code']) && strtoupper((string) ($candidate['country_code'] ?? '')) !== strtoupper((string) $campaign['country_code'])) {
            return $this->result('needs_review', 'GEOGRAPHY_UNCONFIRMED', 'Candidate geography does not match the campaign target.', 0.5);
        }
        foreach (['region', 'city'] as $geographyField) {
            $requested = trim((string) ($campaign[$geographyField] ?? ''));
            if ($requested !== '' && mb_strtolower(trim((string) ($candidate[$geographyField] ?? ''))) !== mb_strtolower($requested)) {
                return $this->result('needs_review', 'GEOGRAPHY_UNCONFIRMED', 'Candidate regional location does not match the campaign target.', 0.5);
            }
        }
        if (! empty($campaign['industry']) && mb_strtolower(trim((string) ($candidate['industry'] ?? ''))) !== mb_strtolower(trim((string) $campaign['industry']))) {
            return $this->result('needs_review', 'INDUSTRY_UNCONFIRMED', 'Candidate industry does not match the campaign target.', 0.5);
        }
        return $this->result('eligible', 'VERIFIED_TARGET_MATCH', 'Authorized source, verified official website, target geography, and target industry are supported by candidate evidence.', 0.9);
    }

    private function result(string $status, string $code, string $reason, float $confidence): array
    {
        return ['status' => $status, 'reason_code' => $code, 'reason' => $reason, 'confidence' => $confidence];
    }
}
