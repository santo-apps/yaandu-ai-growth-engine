<?php

namespace App\Crawling;

use SimpleXMLElement;

final class SitemapParser
{
    public function urls(string $xml, int $limit = 500): array
    {
        libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (! $root) return [];
        $result = [];
        foreach ($root->url as $entry) {
            $loc = trim((string) $entry->loc);
            if ($loc !== '') $result[] = $loc;
            if (count($result) >= $limit) break;
        }
        return array_values(array_unique($result));
    }
}
