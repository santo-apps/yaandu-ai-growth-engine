<?php

namespace App\WebsiteResolution;

final class CandidateDiscoveryResultClassifier
{
    private const DIRECTORY_HOSTS = ['facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'youtu.be', 'x.com', 'twitter.com', 'yelp.com', 'yellowpages.com', 'tripadvisor.com', 'foursquare.com', 'google.com', 'yelp.ae', 'yellowpages.ae'];
    private const MARKETPLACE_HOSTS = ['amazon.com', 'amazon.in', 'amazon.ae', 'noon.com', 'ebay.com', 'etsy.com', 'alibaba.com', 'flipkart.com'];

    public function classify(?string $url, ?string $mimeType = null): string
    {
        if ($url === null || trim($url) === '') return 'UNKNOWN';
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return 'UNKNOWN';
        $host = strtolower((string) $parts['host']);
        $path = strtolower((string) ($parts['path'] ?? ''));
        if (in_array($host, ['wikidata.org', 'www.wikidata.org', 'wikipedia.org'], true) || str_ends_with($host, '.wikipedia.org')) return 'UNKNOWN';
        if (preg_match('/\.(pdf|docx?|xlsx?|pptx?|csv)(?:$|\?)/i', $path) || str_contains(strtolower((string) $mimeType), 'pdf')) return 'DOCUMENT';
        foreach (self::DIRECTORY_HOSTS as $suffix) if ($host === $suffix || str_ends_with($host, '.'.$suffix)) return 'SOCIAL';
        foreach (self::MARKETPLACE_HOSTS as $suffix) if ($host === $suffix || str_ends_with($host, '.'.$suffix)) return 'MARKETPLACE';
        if (preg_match('/(^|\.)(gov|gov\.in|gov\.ae|government)$/i', $host) || str_contains($host, '.gov.')) return 'GOVERNMENT';
        if (preg_match('/(^|\.)(edu|ac\.in|edu\.ae)$/i', $host) || str_contains($host, '.edu.')) return 'ACADEMIC';
        if (preg_match('/(^|\.)(news|press|media)$/i', $host) || preg_match('/\/(news|press|media)\//', $path)) return 'NEWS';
        if (preg_match('/directory|yellow.?pages|business.?listing|company.?listing|listing|maps/i', $path)) return 'DIRECTORY';
        return 'POSSIBLE_OFFICIAL_SITE';
    }
}
