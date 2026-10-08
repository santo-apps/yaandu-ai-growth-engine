<?php

namespace App\WebsiteResolution;

use App\Discovery\DomainNormalizer;

final class CandidateDiscoveryRanker
{
    /** @return list<array{domain:string,url:string,discovery_score:int,discovery_rank:int,evidence:array,result_type:string,source_count:int,signals:array}> */
    public function rank(array $results): array
    {
        $domains = [];
        $normalizer = app(DomainNormalizer::class);
        $classifier = app(CandidateDiscoveryResultClassifier::class);
        foreach ($results as $result) {
            $url = (string) ($result['target_url'] ?? $result['result_url'] ?? '');
            if ($url === '') continue;
            try { $normalized = $normalizer->normalize($url); } catch (\Throwable) { continue; }
            $domain = $normalized['normalized_domain'];
            $type = $classifier->classify($url);
            if (in_array($type, ['SOCIAL', 'DIRECTORY', 'MARKETPLACE', 'NEWS', 'GOVERNMENT', 'ACADEMIC', 'DOCUMENT', 'UNKNOWN'], true)) continue;
            $source = (string) ($result['source'] ?? 'unknown');
            $evidence = (array) ($result['evidence'] ?? []);
            foreach ($evidence as &$item) {
                $item['source'] ??= $source;
                $item['source_reference'] ??= (string) ($result['source_reference'] ?? '');
            }
            unset($item);
            $rank = max(1, (int) ($result['rank'] ?? 10));
            $row = $domains[$domain] ?? ['domain' => $domain, 'url' => $normalized['normalized_url'], 'score' => 0, 'evidence' => [], 'sources' => [], 'best_rank' => PHP_INT_MAX, 'signals' => [], 'result_type' => $type];
            $row['best_rank'] = min($row['best_rank'], $rank);
            $row['sources'][$source] = true;
            $row['score'] = max($row['score'], max(0, 30 - ($rank - 1) * 3));
            if ($type === 'POSSIBLE_OFFICIAL_SITE') { $row['score'] += 5; $row['signals']['possible_official_site_type'] = 5; }
            if (in_array($type, ['GOVERNMENT', 'ACADEMIC'], true)) { $row['score'] -= 15; $row['signals']['non_commercial_site_type'] = -15; }
            foreach ($evidence as $item) {
                $signal = (string) ($item['signal'] ?? 'source_evidence');
                $row['evidence'][] = $item;
                $details = (array) ($item['details'] ?? []);
                $matchingFields = (array) ($details['matching_fields'] ?? []);
                if (in_array($signal, ['name_match', 'wikidata_name_match', 'identity_name_match'], true) || in_array('business_name', $matchingFields, true)) {
                    $row['score'] += 15; $row['signals']['business_name_evidence'] = 15;
                }
                if (in_array($signal, ['phone_match', 'public_phone_match'], true) || in_array('phone', $matchingFields, true)) {
                    $row['score'] += 25; $row['signals']['public_phone_corroboration'] = 25;
                }
                if (in_array($signal, ['location_match', 'city_match', 'address_match'], true) || array_intersect(['city', 'country', 'address'], $matchingFields)) {
                    $row['score'] += 10; $row['signals']['location_evidence'] = 10;
                }
                if ($signal === 'direct_osm_website' || $signal === 'wikidata_official_website') { $row['score'] += 25; $row['signals']['explicit_official_site_claim'] = 25; }
                elseif (in_array($signal, ['brand_website', 'operator_website', 'wikidata_brand_website', 'wikidata_operator_website'], true)) {
                    $row['score'] += 8; $row['signals']['brand_or_operator_site_claim'] = 8;
                }
                if ($signal === 'directory_business_link') { $row['score'] += 12; $row['signals']['explicit_directory_outbound_link'] = 12; }
                if (($item['polarity'] ?? null) === 'negative') {
                    $penalty = min(25, abs((int) ($item['points'] ?? 0)));
                    $row['score'] -= $penalty;
                    $row['signals']['source_conflict'] = -$penalty;
                }
            }
            $domains[$domain] = $row;
        }
        foreach ($domains as &$row) {
            $sourceCount = count($row['sources']);
            if ($sourceCount > 1) { $row['score'] += min(15, ($sourceCount - 1) * 10); $row['signals']['independent_sources'] = min(15, ($sourceCount - 1) * 10); }
            $row['discovery_score'] = min(100, (int) $row['score']);
            $row['source_count'] = $sourceCount;
            unset($row['score'], $row['sources'], $row['best_rank']);
        }
        unset($row);
        $ranked = array_values($domains);
        usort($ranked, static fn ($left, $right) => $right['discovery_score'] <=> $left['discovery_score'] ?: strcmp($left['domain'], $right['domain']));
        foreach ($ranked as $index => &$row) $row['discovery_rank'] = $index + 1;
        unset($row);

        return $ranked;
    }
}
