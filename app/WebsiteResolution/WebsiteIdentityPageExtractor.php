<?php

namespace App\WebsiteResolution;

use App\Crawling\UrlResolver;

final class WebsiteIdentityPageExtractor
{
    public function extract(string $html, string $url): array
    {
        $title = $this->first('/<title\b[^>]*>(.*?)<\/title>/is', $html);
        $h1 = $this->first('/<h1\b[^>]*>(.*?)<\/h1>/is', $html);
        $jsonLd = [];
        preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scripts);
        foreach (array_slice($scripts[1] ?? [], 0, 12) as $script) {
            $data = json_decode(trim($script), true);
            foreach (is_array($data) ? ($this->flatten($data)) : [] as $item) {
                $type = $item['@type'] ?? null;
                if (is_array($type)) $type = implode(' ', $type);
                if (! is_string($type) || ! preg_match('/Organization|LocalBusiness|Corporation|Store|ProfessionalService/i', $type)) continue;
                $address = $item['address'] ?? [];
                if (is_string($address)) $address = ['streetAddress' => $address];
                $jsonLd[] = ['name' => $item['name'] ?? null, 'legal_name' => $item['legalName'] ?? null,
                    'url' => $item['url'] ?? null, 'telephone' => $item['telephone'] ?? null, 'email' => $item['email'] ?? null,
                    'address' => implode(' ', array_filter([(string) ($address['streetAddress'] ?? ''), (string) ($address['postalCode'] ?? '')])),
                    'city' => $address['addressLocality'] ?? null, 'country' => is_array($address['addressCountry'] ?? null) ? ($address['addressCountry']['name'] ?? null) : ($address['addressCountry'] ?? null),
                    'same_as' => array_slice((array) ($item['sameAs'] ?? []), 0, 10)];
            }
        }
        $site = $jsonLd[0] ?? [];
        $site['title'] = $title;
        $site['name'] ??= $h1;
        $site['name_is_structured'] = isset($jsonLd[0]['name']);
        $site['source_url'] = $url;
        $site['description'] = $this->first('/<meta\b[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)/is', $html);
        $site['text_excerpt'] = mb_substr(trim(preg_replace('/\\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) ?? ''), 0, 4000);
        $site['structured_data'] = array_slice($jsonLd, 0, 10);
        return $site;
    }

    /** @return list<array{url:string,type:string}> */
    public function identityLinks(string $html, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];
            $links = [];
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $href = trim((string) $anchor->getAttribute('href'));
                if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:mailto|tel|javascript):/i', $href)) continue;
                $label = trim(implode(' ', array_filter([
                    $anchor->textContent,
                    $anchor->getAttribute('aria-label'),
                    $anchor->getAttribute('title'),
                ])));
                $normalizedLabel = mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?? '');
                $type = preg_match('/\b(contact|contact us|get in touch|reach us)\b/u', $normalizedLabel)
                    ? 'contact'
                    : (preg_match('/\b(about|about us|our story|who we are|company)\b/u', $normalizedLabel) ? 'about' : null);
                if ($type === null) continue;
                try { $url = (new UrlResolver())->resolve($baseUrl, $href); }
                catch (\Throwable) { continue; }
                $links[$url] = ['url' => $url, 'type' => $type];
                if (count($links) >= 12) break;
            }
            return array_values($links);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function first(string $pattern, string $html): ?string
    {
        if (! preg_match($pattern, $html, $match)) return null;
        return mb_substr(trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5)), 0, 500);
    }

    private function flatten(array $data): array
    {
        if (array_is_list($data)) { $out = []; foreach ($data as $item) if (is_array($item)) $out = [...$out, ...$this->flatten($item)]; return $out; }
        if (isset($data['@graph']) && is_array($data['@graph'])) return $this->flatten($data['@graph']);
        return [$data];
    }
}
