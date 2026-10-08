<?php

namespace App\WebsiteResolution;

use RuntimeException;

/** Explicit, local-only search fixture provider. Fixture URLs are test data, never generated from a business name. */
final class DeterministicCandidateDomainDiscoverySource implements CandidateDomainDiscoverySourceInterface
{
    public function name(): string { return 'deterministic_candidate_fixture'; }

    public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch
    {
        if (! app()->environment(['local', 'testing'])) throw new RuntimeException('Deterministic candidate fixtures are unavailable outside local/testing.');
        $key = app(BusinessIdentityNormalizer::class)->name($identity->businessName);
        $fixture = (array) config('candidate_discovery.deterministic_fixtures.'.$key, []);
        $mode = (string) ($fixture['mode'] ?? 'no_result');
        if ($mode === 'timeout') throw new RuntimeException('Deterministic timeout fixture.');
        $results = (array) ($fixture['results'] ?? []);
        if ($mode === 'positive' && $results === []) $results = [$this->row($fixture, 1)];
        if ($mode === 'multiple' && $results === []) $results = [$this->row($fixture, 1), $this->row(array_replace($fixture, ['url' => $fixture['second_url'] ?? null, 'source_reference' => 'fixture:second']), 2)];
        if ($mode === 'duplicate_domain' && $results === []) {
            $row = $this->row($fixture, 1);
            $results = [$row, array_replace($row, ['query' => 'alternate fixture query', 'rank' => 2, 'source_reference' => 'fixture:duplicate'])];
        }
        if ($mode === 'directory' && $results === []) $results = [[
            'query' => 'deterministic directory fixture', 'result_url' => $fixture['result_url'] ?? 'https://directory.example/directory/listing/business',
            'target_url' => $fixture['target_url'] ?? null, 'title' => 'Directory listing', 'snippet' => 'Explicit website link in test fixture.',
            'rank' => 1, 'source_reference' => 'fixture:directory', 'evidence' => empty($fixture['target_url']) ? [] : [['signal' => 'directory_business_link',
                'polarity' => 'neutral', 'points' => 0, 'summary' => 'Directory explicitly links this website in fixture data.', 'details' => []]],
        ]];
        if ($mode === 'social' && $results === []) $results = [$this->row(array_replace($fixture, ['url' => 'https://www.instagram.com/example-business/']), 1)];
        if ($mode === 'conflicting_identity' && $results === []) {
            $row = $this->row($fixture, 1);
            $row['evidence'] = [['signal' => 'source_identity_conflict', 'polarity' => 'negative', 'points' => -25,
                'summary' => 'Fixture describes a conflicting location or business identity.', 'details' => ['matching_fields' => []]]];
            $results = [$row];
        }
        if ($mode === 'malformed_result' && $results === []) $results = [[
            'query' => 'fixture malformed result', 'result_url' => 'https://', 'target_url' => 'javascript:alert(1)',
            'title' => str_repeat('x', 1000), 'snippet' => str_repeat('untrusted ', 1000), 'rank' => 0,
            'source_reference' => 'fixture:malformed', 'evidence' => [],
        ]];
        return new CandidateDiscoveryBatch(array_slice($results, 0, $context->maxRawResultsPerSource),
            array_slice((array) ($fixture['queries'] ?? ['deterministic fixture query']), 0, $context->maxQueries),
            ['fixture_mode' => $mode, 'deterministic' => true], (array) ($fixture['failures'] ?? []));
    }

    private function row(array $fixture, int $rank): array
    {
        return ['query' => 'deterministic fixture query', 'result_url' => $fixture['result_url'] ?? $fixture['url'] ?? null,
            'target_url' => $fixture['target_url'] ?? $fixture['url'] ?? null, 'title' => $fixture['title'] ?? 'Fixture business website',
            'snippet' => $fixture['snippet'] ?? 'Deterministic local fixture evidence.', 'rank' => $rank,
            'source_reference' => $fixture['source_reference'] ?? 'fixture:'.$rank,
            'evidence' => (array) ($fixture['evidence'] ?? [['signal' => 'name_match', 'polarity' => 'neutral', 'points' => 0,
                'summary' => 'Deterministic fixture; not a production identity claim.', 'details' => []]])];
    }
}
