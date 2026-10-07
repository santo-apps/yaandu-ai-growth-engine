<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WikidataClient
{
    public function officialWebsites(string $entityId): array
    {
        if (! preg_match('/^Q[1-9][0-9]{0,11}$/', $entityId)) return [];
        $cacheKey = 'website-resolution:wikidata:'.strtolower($entityId);
        return Cache::remember($cacheKey, max(3600, (int) config('website_resolution.wikidata_cache_seconds', 604800)), function () use ($entityId): array {
            $response = Http::acceptJson()->withHeaders(['User-Agent' => (string) config('website_resolution.user_agent')])
                ->connectTimeout(3)->timeout(8)->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities', 'ids' => $entityId, 'props' => 'claims|labels|sitelinks', 'languages' => 'en', 'format' => 'json',
                ]);
            if (! $response->successful() || strlen($response->body()) > 512_000) throw new RuntimeException('Wikidata source unavailable.');
            $entity = $response->json('entities.'.$entityId);
            $claims = (array) ($entity['claims'] ?? []);
            $urls = [];
            foreach ((array) ($claims['P856'] ?? []) as $claim) {
                $url = $claim['mainsnak']['datavalue']['value'] ?? null;
                if (is_string($url) && $url !== '') $urls[] = $url;
            }
            return array_slice(array_values(array_unique($urls)), 0, 3);
        });
    }
}
