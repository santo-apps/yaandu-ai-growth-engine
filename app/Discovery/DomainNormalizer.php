<?php

namespace App\Discovery;

use InvalidArgumentException;

final class DomainNormalizer
{
    /** @return array{original_url:string,normalized_domain:string,normalized_url:string} */
    public function normalize(string $value): array
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\\\\]/', $value)) {
            throw new InvalidArgumentException('Enter a valid public website URL or domain.');
        }
        $url = preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $value) ? $value : 'https://'.$value;
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only public HTTP and HTTPS website URLs are supported.');
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            throw new InvalidArgumentException('The website URL uses an unsupported port.');
        }
        $rawHost = strtolower(trim($parts['host'], '[]'));
        if (str_ends_with($rawHost, '.') || in_array($rawHost, ['localhost', 'metadata.google.internal'], true) || str_ends_with($rawHost, '.localhost') || str_ends_with($rawHost, '.local')) {
            throw new InvalidArgumentException('Local and metadata service hosts are not allowed.');
        }
        $host = $rawHost;
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('Private and reserved IP addresses are not allowed.');
        }
        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) throw new InvalidArgumentException('The internationalized domain is invalid.');
            $host = strtolower($ascii);
        } elseif (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw new InvalidArgumentException('This runtime cannot normalize that internationalized domain.');
        }
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        if ($host === '' || strlen($host) > 253) throw new InvalidArgumentException('The website domain is invalid.');
        if (! filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Enter a valid fully qualified public domain.');
        }
        $scheme = strtolower($parts['scheme']);
        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $path = $parts['path'] ?? '/';
        $normalizedUrl = $scheme.'://'.$displayHost.$path;
        if (isset($parts['query'])) $normalizedUrl .= '?'.$parts['query'];

        return ['original_url' => $value, 'normalized_domain' => $host, 'normalized_url' => $normalizedUrl];
    }
}
