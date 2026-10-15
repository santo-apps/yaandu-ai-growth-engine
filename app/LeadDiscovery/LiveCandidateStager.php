<?php

namespace App\LeadDiscovery;

use App\Discovery\DomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LiveCandidateStager
{
    public function __construct(private readonly DomainNormalizer $domains, private readonly CandidateIdentityResolver $identities) {}

    /** @return array{created:bool,duplicate:bool,verification_id:?string} */
    public function stage(string $tenantId, object $campaign, object $run, object $source, object $checkpoint, array $result, int $rank): array
    {
        $name = trim((string) ($result['company_name'] ?? ''));
        if ($name === '' || $name === 'Unnamed legal entity') return ['created' => false, 'duplicate' => false, 'verification_id' => null];
        $url = trim((string) ($result['website'] ?? ''));
        $normalizedUrl = null; $domain = null;
        if ($url !== '') {
            try {
                $normalized = $this->domains->normalize($url);
                $domain = $normalized['normalized_domain'];
                if (filter_var($domain, FILTER_VALIDATE_IP)) return ['created' => false, 'duplicate' => false, 'verification_id' => null];
                $normalizedUrl = strtolower((string) parse_url($normalized['normalized_url'], PHP_URL_SCHEME)).'://'.$domain.'/';
            } catch (\Throwable) { $url = ''; }
        }
        $sourceKey = (string) $source->source_key;
        $identifiers = [
            'registration_identifier' => $result['registration_identifier'] ?? null,
            'lei' => $result['lei'] ?? null,
            'wikidata_qid' => $result['wikidata_qid'] ?? null,
            'osm_object_id' => $result['osm_object_id'] ?? null,
        ];
        $normalizedName = $this->normalizeName($name);
        $country = $this->countryCode($result['country_code'] ?? null);
        $city = trim((string) ($result['city'] ?? '')) ?: null;
        $result['namespaced_registration_identifier'] = $this->namespacedRegistrationIdentifier($result, $country);
        unset($identifiers['registration_identifier']);
        $identifiers['namespaced_registration_identifier'] = $result['namespaced_registration_identifier'];
        $identifiers['osm_object_id'] = $this->typedOsmObjectId($result);
        $match = $this->identities->resolve($tenantId, $domain, $identifiers, $normalizedName, $country, $city);
        $sourceRecordId = (string) ($result['source_record_id'] ?? hash('sha256', $normalizedName.'|'.($country ?? '').'|'.($city ?? '')));
        $sourceRecordType = (string) ($result['source_record_type'] ?? $sourceKey);
        $hasUnnamespacedRegistration = trim((string) ($result['registration_identifier'] ?? '')) !== ''
            && $result['namespaced_registration_identifier'] === null;
        $stableRecordId = $sourceKey === 'osm_overpass'
            ? $identifiers['osm_object_id']
            : ($result['namespaced_registration_identifier'] ?? ($hasUnnamespacedRegistration ? null : ($result['source_record_id'] ?? null)));
        $fingerprintInput = $domain ? 'domain:'.$domain : ($stableRecordId !== null
            ? $sourceKey.':'.$sourceRecordType.':'.($country ?? 'unknown').':'.$stableRecordId
            : $sourceKey.':checkpoint:'.$checkpoint->id.':rank:'.$rank);
        $fingerprint = hash('sha256', $fingerprintInput);
        $existingByFingerprint = DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('fingerprint', $fingerprint)->first();
        $strongMatch = in_array($match['match'], ['normalized_domain', 'strong_identifier'], true) ? $match['candidate'] : null;
        $existing = $strongMatch ?? $existingByFingerprint;
        $sourceUrl = $this->safeSourceUrl((string) ($result['source_url'] ?? ''));
        $now = now();
        $attribution = array_replace((array) ($result['attribution'] ?? []), [
            'source_key' => $sourceKey, 'source_id' => $source->id, 'run_id' => $run->id,
            'source_record_id' => (string) ($result['source_record_id'] ?? ''), 'source_record_type' => (string) ($result['source_record_type'] ?? $sourceKey),
            'license_or_authorization' => mb_substr((string) data_get(json_decode((string) $source->configuration, true), 'license_or_authorization', ''), 0, 250),
            'authorization_status' => $source->authorization_status, 'storage_rights_status' => $source->storage_rights_status,
            'retrieved_at' => $now->toIso8601String(),
        ]);

        if ($existing) {
            $attachWebsite = $domain !== null && empty($existing->claimed_website);
            if ($attachWebsite) DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $existing->id)->update([
                'claimed_website' => $normalizedUrl, 'normalized_domain' => $domain, 'official_domain_status' => 'unverified',
                'processing_status' => 'discovered', 'validation_status' => 'pending', 'validation_reason_code' => null,
                'validation_reason' => null, 'updated_at' => $now,
            ]);
            $this->storeEvidence($tenantId, (string) $existing->id, (string) $campaign->id, (string) $source->id, $sourceUrl, $result, $attribution, $now);
            $this->mergeCanonicalFields($tenantId, (string) $existing->id);
            return ['created' => false, 'duplicate' => true, 'verification_id' => $attachWebsite ? (string) $existing->id : null];
        }

        $id = (string) Str::uuid();
        $possibleMatch = $match['match'] === 'possible_match' && $existing === null;
        $rawMetadata = [
            'query_key' => $checkpoint->query_key, 'query' => mb_substr((string) $checkpoint->query_text, 0, 300),
            'source_key' => $sourceKey, 'source_record_id' => $result['source_record_id'] ?? null,
            'source_record_type' => $result['source_record_type'] ?? $sourceKey, 'raw' => $this->boundedMetadata($result['raw_metadata'] ?? []),
            'rank' => $rank, 'provider_request_id' => $result['_request_id'] ?? null, 'collected_at' => $now->toIso8601String(),
            'possible_match_candidate_id' => $possibleMatch ? $match['candidate']->id : null,
            'attribution' => $attribution,
        ];
        DB::table('lead_discovery_candidates')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id,
            'fingerprint' => $fingerprint, 'company_name' => mb_substr($name, 0, 255), 'normalized_name' => mb_substr($normalizedName, 0, 255),
            'claimed_website' => $normalizedUrl, 'normalized_domain' => $domain, 'official_domain_status' => $domain ? 'unverified' : 'unknown',
            'industry' => $this->safeScalar($result['industry'] ?? null, 150), 'country_code' => $country,
            'region' => $this->safeScalar($result['region'] ?? null, 120), 'city' => $this->safeScalar($city, 120),
            'location' => $this->safeScalar(implode(', ', array_filter([$city, $result['region'] ?? null, $country])), 255),
            'raw_metadata' => json_encode($rawMetadata, JSON_INVALID_UTF8_SUBSTITUTE),
            'processing_status' => $possibleMatch ? 'needs_review' : ($domain ? 'discovered' : 'needs_review'),
            'validation_status' => $domain ? 'pending' : 'unknown', 'promotion_status' => 'not_promoted',
            'validation_reason_code' => $possibleMatch ? 'POSSIBLE_CROSS_SOURCE_MATCH' : ($domain ? null : 'WEBSITE_UNKNOWN'),
            'validation_reason' => $possibleMatch ? 'A same-name, country and city record may be the same business; review before merging.' : ($domain ? null : 'No official website was provided by this source; no domain was inferred.'),
            'eligibility_status' => 'needs_review', 'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->storeEvidence($tenantId, $id, (string) $campaign->id, (string) $source->id, $sourceUrl, $result, $attribution, $now);
        $this->mergeCanonicalFields($tenantId, $id);
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => null,
            'action' => 'lead_discovery.candidate_discovered', 'subject_type' => 'lead_discovery', 'subject_id' => $id,
            'metadata' => json_encode(['campaign_id' => $campaign->id, 'run_id' => $run->id, 'source_id' => $source->id,
                'source_record_id' => $result['source_record_id'] ?? null, 'match_status' => $possibleMatch ? 'possible_match' : 'new']), 'created_at' => $now]);

        return ['created' => true, 'duplicate' => false, 'verification_id' => $domain ? $id : null];
    }

    private function storeEvidence(string $tenantId, string $candidateId, string $campaignId, string $sourceId, ?string $sourceUrl, array $result, array $attribution, mixed $now): void
    {
        $fields = [
            'company_name' => $result['company_name'] ?? null, 'legal_name' => $result['legal_name'] ?? null,
            'claimed_website' => $result['website'] ?? null, 'business_email' => $result['email'] ?? null, 'business_phone' => $result['phone'] ?? null,
            'industry' => $result['industry'] ?? null, 'source_industry' => $result['source_industry'] ?? null,
            'country_code' => $result['country_code'] ?? null, 'region' => $result['region'] ?? null,
            'city' => $result['city'] ?? null, 'address' => $result['address'] ?? null, 'postal_code' => $result['postal_code'] ?? null,
            'search_title' => $result['title'] ?? null, 'search_snippet' => $result['snippet'] ?? null,
            'latitude' => $result['latitude'] ?? null, 'longitude' => $result['longitude'] ?? null,
            'registration_identifier' => $result['registration_identifier'] ?? null, 'lei' => $result['lei'] ?? null,
            'namespaced_registration_identifier' => $result['namespaced_registration_identifier'] ?? null,
            'wikidata_qid' => $result['wikidata_qid'] ?? null, 'osm_object_id' => $result['osm_object_id'] ?? null,
            'entity_status' => $result['entity_status'] ?? null, 'source_category' => $result['source_category'] ?? null,
        ];
        foreach ($fields as $field => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') continue;
            $raw = mb_substr(trim((string) $value), 0, 2000);
            $evidenceRaw = $field === 'namespaced_registration_identifier'
                ? mb_substr(trim((string) ($result['registration_identifier'] ?? '')), 0, 2000)
                : $raw;
            $normalizedEvidence = match ($field) {
                'country_code' => $this->countryCode($raw),
                'osm_object_id' => $this->typedOsmObjectId($result) ?? $raw,
                default => $raw,
            };
            DB::table('lead_discovery_candidate_evidence')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'candidate_id' => $candidateId, 'campaign_id' => $campaignId, 'source_id' => $sourceId,
                'field_name' => $field, 'evidence_type' => 'open_source_record', 'source_url' => $sourceUrl,
                'raw_value' => $evidenceRaw, 'normalized_value' => $normalizedEvidence,
                'confidence_score' => in_array($field, ['legal_name', 'registration_identifier', 'namespaced_registration_identifier', 'lei'], true) ? 0.95 : 0.75,
                'collected_at' => $now, 'attribution' => json_encode($attribution, JSON_INVALID_UTF8_SUBSTITUTE), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function safeSourceUrl(string $url): ?string
    {
        if ($url === '') return null;
        try {
            $normalized = $this->domains->normalize($url);
            return strtolower((string) parse_url($normalized['normalized_url'], PHP_URL_SCHEME)).'://'.$normalized['normalized_domain'].'/'.ltrim((string) parse_url($normalized['normalized_url'], PHP_URL_PATH), '/');
        } catch (\Throwable) { return null; }
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\pL\pN ]/u', ' ', $name) ?: $name)) ?: '');
    }

    private function countryCode(mixed $country): ?string
    {
        if (! is_scalar($country)) return null;
        $code = strtoupper(trim((string) $country));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    private function namespacedRegistrationIdentifier(array $result, ?string $country): ?string
    {
        $identifier = trim((string) ($result['registration_identifier'] ?? ''));
        $jurisdiction = strtoupper(trim((string) ($result['registration_identifier_jurisdiction'] ?? '')));
        $registry = strtoupper(trim((string) ($result['registration_identifier_registry'] ?? '')));
        $type = strtoupper(trim((string) ($result['registration_identifier_type'] ?? '')));
        if ($identifier === '' || $country === null || $jurisdiction !== $country
            || preg_match('/^[A-Z]{2}$/', $jurisdiction) !== 1
            || preg_match('/^[A-Z0-9_-]{1,32}$/', $registry) !== 1
            || preg_match('/^[A-Z0-9_-]{1,32}$/', $type) !== 1) return null;

        // Preserve punctuation, but normalize case and whitespace so only a
        // deliberate, adapter-provided jurisdiction/registry/type namespace
        // can produce a strong registry match.
        $normalized = mb_strtoupper(preg_replace('/\s+/u', '', $identifier) ?? $identifier);
        if ($normalized === '' || mb_strlen($normalized) > 200) return null;

        return $jurisdiction.':'.$registry.':'.$type.':'.$normalized;
    }

    private function typedOsmObjectId(array $result): ?string
    {
        $identifier = trim((string) ($result['osm_object_id'] ?? ''));
        if (preg_match('/^(?:node|way|relation)\/[1-9][0-9]*$/', $identifier) === 1) return $identifier;

        $type = strtolower(trim((string) ($result['source_record_type'] ?? '')));
        if (in_array($type, ['node', 'way', 'relation'], true) && preg_match('/^[1-9][0-9]*$/', $identifier) === 1) {
            return $type.'/'.$identifier;
        }

        return null;
    }

    private function safeScalar(mixed $value, int $limit): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, $limit) : null;
    }

    private function boundedMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) return [];
        $json = json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($json) || strlen($json) > 10000) return ['truncated' => true, 'sha256' => hash('sha256', (string) $json)];
        return $metadata;
    }

    /** Select display fields only from preserved source evidence, using deterministic per-field source precedence. */
    private function mergeCanonicalFields(string $tenantId, string $candidateId): void
    {
        $evidence = DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenantId)->where('candidate_id', $candidateId)
            ->whereIn('field_name', ['company_name', 'legal_name', 'industry', 'country_code', 'region', 'city'])->get();
        $priorities = [
            'company_name' => ['india_ogd_mca' => 100, 'gleif' => 90, 'wikidata' => 80, 'osm_overpass' => 70, 'csv_upload' => 60, 'brave_search' => 40],
            'legal_name' => ['india_ogd_mca' => 100, 'gleif' => 90, 'wikidata' => 80, 'osm_overpass' => 65, 'csv_upload' => 60],
            'industry' => ['india_ogd_mca' => 100, 'wikidata' => 90, 'osm_overpass' => 80, 'csv_upload' => 60, 'brave_search' => 40],
            'country_code' => ['india_ogd_mca' => 100, 'gleif' => 95, 'wikidata' => 85, 'osm_overpass' => 80, 'csv_upload' => 60],
            'region' => ['india_ogd_mca' => 100, 'gleif' => 95, 'osm_overpass' => 90, 'wikidata' => 70, 'csv_upload' => 60],
            'city' => ['india_ogd_mca' => 100, 'gleif' => 95, 'osm_overpass' => 90, 'wikidata' => 70, 'csv_upload' => 60],
        ];
        $best = [];
        foreach ($evidence as $item) {
            $attribution = is_array($item->attribution) ? $item->attribution : (json_decode((string) $item->attribution, true) ?: []);
            $sourceKey = (string) ($attribution['source_key'] ?? '');
            $field = $item->field_name === 'legal_name' ? 'company_name' : $item->field_name;
            $priority = (int) ($priorities[$field][$sourceKey] ?? 0) + ($item->field_name === 'legal_name' ? 5 : 0);
            if ($priority <= 0 || trim((string) $item->normalized_value) === '') continue;
            $rank = [$priority, (float) $item->confidence_score, (string) $item->normalized_value];
            if (! isset($best[$field]) || $rank[0] > $best[$field]['rank'][0]
                || ($rank[0] === $best[$field]['rank'][0] && $rank[1] > $best[$field]['rank'][1])
                || ($rank[0] === $best[$field]['rank'][0] && $rank[1] === $best[$field]['rank'][1] && $rank[2] < $best[$field]['rank'][2])) {
                $best[$field] = ['value' => trim((string) $item->normalized_value), 'rank' => $rank];
            }
        }
        $updates = [];
        if (isset($best['company_name'])) {
            $updates['company_name'] = mb_substr($best['company_name']['value'], 0, 255);
            $updates['normalized_name'] = mb_substr($this->normalizeName($best['company_name']['value']), 0, 255);
        }
        foreach (['industry', 'country_code', 'region', 'city'] as $field) if (isset($best[$field])) $updates[$field] = mb_substr($best[$field]['value'], 0, ['industry' => 150, 'country_code' => 2, 'region' => 120, 'city' => 120][$field]);
        if ($updates !== []) DB::table('lead_discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([...$updates, 'updated_at' => now()]);
    }
}
