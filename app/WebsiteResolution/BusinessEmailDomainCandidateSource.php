<?php

namespace App\WebsiteResolution;

final class BusinessEmailDomainCandidateSource implements CandidateDomainDiscoverySourceInterface
{
    public function name(): string { return 'public_business_email'; }

    public function discover(BusinessIdentity $identity, CandidateDiscoveryContext $context): CandidateDiscoveryBatch
    {
        $email = mb_strtolower(trim((string) $identity->publicEmail));
        if (! preg_match('/^[^@\s]+@([^@\s]+)$/', $email, $parts)) return new CandidateDiscoveryBatch();
        $domain = rtrim($parts[1], '.');
        $generic = array_map('mb_strtolower', (array) config('candidate_discovery.generic_email_domains', []));
        if (in_array($domain, $generic, true) || ! str_contains($domain, '.')) return new CandidateDiscoveryBatch();

        return new CandidateDiscoveryBatch([['query' => 'existing_public_business_email', 'result_url' => null,
            'target_url' => 'https://'.$domain.'/', 'title' => 'Public business email domain', 'snippet' => null, 'rank' => 1,
            'source_reference' => 'email-domain:'.hash('sha256', $email), 'result_type' => 'POSSIBLE_OFFICIAL_SITE',
            'metadata' => ['evidence_type' => 'public_business_email_domain'],
            'evidence' => [['signal' => 'email_domain_match', 'polarity' => 'neutral', 'points' => 0,
                'summary' => 'The domain appears in an existing public business email; this does not confirm it as the official website.',
                'details' => ['email_domain' => $domain]]]]], ['existing_public_business_email'], ['result_count' => 1]);
    }
}
