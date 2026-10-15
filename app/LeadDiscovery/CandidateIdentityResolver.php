<?php

namespace App\LeadDiscovery;

use Illuminate\Support\Facades\DB;

final class CandidateIdentityResolver
{
    /** @return array{candidate:?object,match:string} */
    public function resolve(string $tenantId, ?string $domain, array $identifiers, string $normalizedName, ?string $country, ?string $city): array
    {
        if ($domain) {
            $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->first();
            if ($candidate) return ['candidate' => $candidate, 'match' => 'normalized_domain'];
        }
        foreach ($identifiers as $field => $value) {
            if (! in_array($field, ['namespaced_registration_identifier', 'lei', 'wikidata_qid', 'osm_object_id'], true) || ! is_string($value) || trim($value) === '') continue;
            if ($field === 'namespaced_registration_identifier'
                && preg_match('/^[A-Z]{2}:[A-Z0-9_-]{1,32}:[A-Z0-9_-]{1,32}:[A-Z0-9 ._\/-]{1,200}$/', $value) !== 1) continue;
            if ($field === 'osm_object_id' && preg_match('/^(?:node|way|relation)\/[1-9][0-9]*$/', $value) !== 1) continue;
            $candidate = DB::table('lead_discovery_candidate_evidence as evidence')
                ->join('lead_discovery_candidates as candidate', function ($join): void {
                    $join->on('candidate.id', '=', 'evidence.candidate_id')->on('candidate.tenant_id', '=', 'evidence.tenant_id');
                })
                ->where('evidence.tenant_id', $tenantId)->where('evidence.field_name', $field)->where('evidence.normalized_value', $value)
                ->select('candidate.*')->first();
            if ($candidate) return ['candidate' => $candidate, 'match' => 'strong_identifier'];
        }
        if ($normalizedName !== '' && $country && $city) {
            $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('normalized_name', $normalizedName)
                ->where('country_code', $country)->where('city', $city)->first();
            if ($candidate) return ['candidate' => $candidate, 'match' => 'possible_match'];
        }

        return ['candidate' => null, 'match' => 'none'];
    }
}
