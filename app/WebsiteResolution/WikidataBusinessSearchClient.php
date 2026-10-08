<?php

namespace App\WebsiteResolution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class WikidataBusinessSearchClient
{
    private int $cacheHits = 0;
    private int $requestCalls = 0;

    public function cacheHits(): int { return $this->cacheHits; }
    public function requestCalls(): int { return $this->requestCalls; }

    /** @return list<array{id:string,label:string,description:?string,rank:int}> */
    public function search(string $query, int $limit): array
    {
        $query = mb_substr(trim(preg_replace('/\s+/', ' ', str_replace('"', ' ', $query)) ?? ''), 0, 400);
        $limit = min(10, max(1, $limit));
        if ($query === '') return [];
        $key = 'candidate-discovery:wikidata-search:v2:'.hash('sha256', mb_strtolower($query).':'.$limit);
        if (Cache::has($key)) $this->cacheHits++;
        return Cache::remember($key, max(300, (int) config('candidate_discovery.wikidata_cache_seconds', 604800)), function () use ($query, $limit): array {
            $this->reserveRequest();
            $this->requestCalls++;
            $response = Http::acceptJson()->withHeaders(['User-Agent' => (string) config('website_resolution.user_agent')])
                ->connectTimeout(3)->timeout(6)->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbsearchentities', 'search' => $query, 'language' => 'en', 'uselang' => 'en',
                    'type' => 'item', 'limit' => $limit, 'format' => 'json', 'maxlag' => 5,
                ]);
            if (! $response->successful() || strlen($response->body()) > 512_000 || $response->json('error') !== null
                || ! is_array($response->json('search'))) throw new RuntimeException('Wikidata candidate search unavailable.');
            $items = [];
            foreach (array_slice((array) $response->json('search', []), 0, $limit) as $index => $item) {
                $id = (string) ($item['id'] ?? '');
                $label = trim((string) ($item['label'] ?? ''));
                if (! preg_match('/^Q[1-9][0-9]{0,11}$/', $id) || $label === '') continue;
                $items[] = ['id' => $id, 'label' => mb_substr($label, 0, 255),
                    'description' => isset($item['description']) ? mb_substr((string) $item['description'], 0, 500) : null,
                    'rank' => $index + 1];
            }
            return $items;
        });
    }

    /** @param list<string> $entityIds @return array<string,list<string>> */
    public function officialWebsites(array $entityIds): array
    {
        $entityIds = array_values(array_unique(array_filter(array_slice($entityIds, 0, 50), fn ($id) => preg_match('/^Q[1-9][0-9]{0,11}$/', (string) $id))));
        if ($entityIds === []) return [];
        sort($entityIds);
        $key = 'candidate-discovery:wikidata-p856:v2:'.hash('sha256', implode(',', $entityIds));
        if (Cache::has($key)) $this->cacheHits++;
        return Cache::remember($key, max(300, (int) config('candidate_discovery.wikidata_cache_seconds', 604800)), function () use ($entityIds): array {
            $this->reserveRequest();
            $this->requestCalls++;
            $response = Http::acceptJson()->withHeaders(['User-Agent' => (string) config('website_resolution.user_agent')])
                ->connectTimeout(3)->timeout(6)->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities', 'ids' => implode('|', $entityIds), 'props' => 'claims', 'format' => 'json', 'maxlag' => 5,
                ]);
            if (! $response->successful() || strlen($response->body()) > 2_000_000 || $response->json('error') !== null
                || ! is_array($response->json('entities'))) throw new RuntimeException('Wikidata website claims unavailable.');
            $websites = [];
            foreach ((array) $response->json('entities', []) as $entityId => $entity) {
                foreach ((array) ($entity['claims']['P856'] ?? []) as $claim) {
                    $url = $claim['mainsnak']['datavalue']['value'] ?? null;
                    if (is_string($url) && $url !== '') $websites[$entityId][] = mb_substr($url, 0, 2048);
                }
                $websites[$entityId] = array_values(array_unique($websites[$entityId] ?? []));
            }
            return $websites;
        });
    }

    private function reserveRequest(): void
    {
        $allowed = RateLimiter::attempt('candidate-discovery:wikidata-global',
            min(60, max(1, (int) config('candidate_discovery.wikidata_max_requests_per_minute', 30))),
            static fn (): bool => true, 60);
        if (! $allowed) throw new RuntimeException('Wikidata source rate budget reached.');
    }
}
