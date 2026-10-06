<?php

namespace App\Crawling;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class UrlResolver
{
    public function resolve(string $base, string $relative): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri($relative));
    }
}
