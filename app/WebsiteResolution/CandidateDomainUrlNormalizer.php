<?php

namespace App\WebsiteResolution;

use App\Discovery\DomainNormalizer;
use InvalidArgumentException;

final class CandidateDomainUrlNormalizer
{
    private const TRACKING_KEYS = ['gclid', 'dclid', 'fbclid', 'msclkid', 'yclid', 'ref', 'ref_src', 'source'];

    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array{original_url:string,normalized_domain:string,normalized_url:string} */
    public function normalize(string $url): array
    {
        $normalized = $this->domains->normalize($url);
        $parts = parse_url($normalized['normalized_url']);
        if (! is_array($parts) || empty($parts['host'])) throw new InvalidArgumentException('Candidate URL is invalid.');
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) if (in_array(strtolower((string) $key), self::TRACKING_KEYS, true) || str_starts_with(strtolower((string) $key), 'utm_')) unset($query[$key]);
        ksort($query);
        $scheme = strtolower((string) $parts['scheme']);
        $normalized['normalized_url'] = $scheme.'://'.$parts['host'].($parts['path'] ?? '/').($query ? '?'.http_build_query($query) : '');

        return $normalized;
    }
}
