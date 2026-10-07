<?php

namespace App\WebsiteResolution;

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
        $site['source_url'] = $url;
        $site['description'] = $this->first('/<meta\b[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)/is', $html);
        $site['text_excerpt'] = mb_substr(trim(preg_replace('/\\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) ?? ''), 0, 4000);
        return $site;
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
