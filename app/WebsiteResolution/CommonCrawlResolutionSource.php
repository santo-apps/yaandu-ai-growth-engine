<?php

namespace App\WebsiteResolution;

use Throwable;

final class CommonCrawlResolutionSource implements WebsiteResolutionSourceInterface
{
    public function __construct(private readonly CommonCrawlClient $client) {}
    public function name(): string { return 'common_crawl'; }

    public function find(array $identity, array $knownCandidates = []): array
    {
        $out = [];
        foreach (array_slice($knownCandidates, 0, (int) config('website_resolution.max_common_crawl_lookups', 3)) as $candidate) {
            $domain = (string) ($candidate['normalized_domain'] ?? '');
            if ($domain === '') continue;
            try { $captures = $this->client->lookupDomain($domain); }
            catch (Throwable) { throw new \RuntimeException('Common Crawl source unavailable.'); }
            if (! $captures) continue;
            $out[] = ['url' => $candidate['url'], 'source' => $this->name(), 'candidate_type' => $candidate['candidate_type'] ?? 'business',
                'source_reference' => 'commoncrawl:'.$domain, 'existing_domain' => $domain,
                'evidence' => [['signal' => 'common_crawl_historic_capture', 'polarity' => 'positive', 'points' => 5,
                    'summary' => 'Common Crawl has a historical successful HTML capture on this already-discovered domain; this is not proof of business ownership.',
                    'details' => ['captures' => array_slice($captures, 0, 2)]]]];
        }
        return $out;
    }
}
