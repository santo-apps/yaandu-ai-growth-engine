<?php

namespace App\WebsiteResolution;

final class DirectoryDomainClassifier
{
    private const HOSTS = ['facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'youtu.be', 'x.com', 'twitter.com', 'yelp.com', 'yellowpages.com', 'tripadvisor.com', 'foursquare.com', 'google.com'];
    private const TOKENS = ['directory', 'yellowpages', 'business-listing', 'company-listing', 'directory-listing', 'maps'];

    public function classify(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (self::HOSTS as $blocked) if ($host === $blocked || str_ends_with($host, '.'.$blocked)) return 'social_or_directory';
        foreach (self::TOKENS as $token) if (str_contains($path, $token)) return 'directory_page';
        return null;
    }
}
