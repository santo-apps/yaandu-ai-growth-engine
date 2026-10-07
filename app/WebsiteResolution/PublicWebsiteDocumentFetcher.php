<?php

namespace App\WebsiteResolution;

use App\Crawling\RobotsRules;
use App\Crawling\UrlPolicy;
use App\Crawling\PublicSuffixDomainMatcher;
use App\Discovery\DomainNormalizer;
use Throwable;

final class PublicWebsiteDocumentFetcher
{
    private array $robotsBodies = [];

    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly UrlPolicy $policy,
        private readonly RobotsRules $robots,
        private readonly WebsiteIdentityPageExtractor $extractor,
        private readonly DirectoryDomainClassifier $directories,
        private readonly PublicSuffixDomainMatcher $suffixes,
    ) {}

    /** @return array<string,mixed> */
    public function fetch(string $url, array $identity = [], ?int $maxIdentityPages = null): array
    {
        $rawParts = parse_url($url);
        if (! is_array($rawParts) || isset($rawParts['user']) || isset($rawParts['pass'])) throw new \InvalidArgumentException('UNSAFE_URL');
        $url = $this->homepageUrl($url);
        if ($this->directories->classify($url)) throw new \DomainException('DIRECTORY_EVIDENCE');
        try { $normalized = $this->domains->normalize($url); }
        catch (Throwable $error) { throw new \InvalidArgumentException('URL_NORMALIZATION_REJECTED', previous: $error); }
        try { $this->policy->validatePublicHttpUrl($normalized['normalized_url']); }
        catch (Throwable $error) {
            if (str_contains(mb_strtolower($error->getMessage()), 'resolve')) throw new \RuntimeException('DNS_FAILURE', previous: $error);
            throw new \InvalidArgumentException('UNSAFE_URL', previous: $error);
        }

        $homepage = $this->fetchPage($normalized['normalized_url'], $normalized['normalized_domain']);
        $homeIdentity = $this->extractor->extract($homepage['html'], $homepage['url']);
        $pages = [$this->pageEvidence($homepage['url'], 'homepage', $homeIdentity, $homepage['html'])];
        $failures = [];
        $seen = [$homepage['url'] => true];
        $additionalLimit = min(2, max(0, min((int) config('website_resolution.max_identity_pages_per_domain', 2), $maxIdentityPages ?? 2)));
        $links = $this->extractor->identityLinks($homepage['html'], $homepage['url']);

        foreach ($links as $link) {
            if (count($pages) - 1 >= $additionalLimit) break;
            try {
                $target = $this->domains->normalize($link['url']);
                if (! $this->suffixes->sameRegistrableDomain($normalized['normalized_domain'], $target['normalized_domain'])) continue;
                if (isset($seen[$target['normalized_url']])) continue;
                $seen[$target['normalized_url']] = true;
                $page = $this->fetchPage($target['normalized_url'], $target['normalized_domain']);
                $data = $this->extractor->extract($page['html'], $page['url']);
                $pages[] = $this->pageEvidence($page['url'], $link['type'], $data, $page['html']);
            } catch (Throwable $error) {
                $failures[] = $this->failureCode($error);
            }
        }

        $combined = $this->combineIdentity(array_map(static fn (array $page): array => $page['identity'], $pages), $identity);
        $document = $this->normalizeExtracted($combined, $normalized['normalized_url'], $normalized['normalized_domain'], $identity);
        $document['identity_pages'] = array_map(static function (array $page): array {
            unset($page['identity']['text_excerpt']);
            return $page;
        }, $pages);
        $document['_identity_page_failures'] = $failures;
        $document['_fetch_count'] = count($pages) + count($failures);
        $document['_bytes'] = array_sum(array_column($pages, 'bytes'));
        return $document;
    }

    public function extractArchived(string $url, string $html, array $identity = []): array
    {
        $normalized = $this->domains->normalize($url);
        $this->policy->validatePublicHttpUrl($normalized['normalized_url']);
        $this->assertRobotsAllows($normalized['normalized_url'], $normalized['normalized_domain']);
        return [...$this->normalizeExtracted($this->extractor->extract($html, $normalized['normalized_url']), $normalized['normalized_url'], $normalized['normalized_domain'], $identity), '_bytes' => strlen($html)];
    }

    private function normalizeExtracted(array $page, string $url, string $domain, array $identity): array
    {
        $name = trim((string) ($page['name'] ?? $page['title'] ?? $page['text_excerpt'] ?? $identity['organization_name'] ?? ''));
        if ($name === '') throw new \UnexpectedValueException('PARSE_FAILURE');
        return [
            'canonical_url' => $url, 'normalized_domain' => $domain,
            'page_title' => $page['title'] ?? null, 'organization_name' => $page['name'] ?? ($identity['organization_name'] ?? $name),
            'description' => $page['description'] ?? null, 'visible_text_excerpt' => $page['text_excerpt'] ?? null,
            'country' => $page['country'] ?? ($identity['country'] ?? null), 'city' => $page['city'] ?? ($identity['city'] ?? null),
            'address_text' => $page['address'] ?? ($identity['address_text'] ?? null),
            'phone_values' => array_values(array_unique(array_filter([...(array) ($page['telephone'] ?? []), ...((array) ($identity['phone_values'] ?? []))]))),
            'email_values' => array_values(array_unique(array_filter([...(array) ($page['email'] ?? []), ...((array) ($identity['email_values'] ?? []))]))),
            'structured_data' => ! empty($page['structured_data']) ? ['source_url' => $url, 'entities' => array_slice($page['structured_data'], 0, 10)] : [],
        ];
    }

    /** @return array{url:string,type:string,bytes:int,content_hash:string,identity:array<string,mixed>} */
    private function pageEvidence(string $url, string $type, array $identity, string $html): array
    {
        return ['url' => $url, 'type' => $type, 'bytes' => strlen($html), 'content_hash' => hash('sha256', $html),
            'retrieved_at' => now()->toIso8601String(), 'identity' => array_intersect_key($identity, array_flip([
                'title', 'name', 'name_is_structured', 'legal_name', 'description', 'address', 'city', 'country',
                'telephone', 'email', 'structured_data', 'source_url',
            ]))];
    }

    private function combineIdentity(array $pages, array $seed): array
    {
        $home = $pages[0] ?? [];
        $merged = $home;
        $structuredEntities = [];
        $phones = []; $emails = []; $descriptions = [];
        foreach ($pages as $index => $page) {
            foreach ((array) ($page['structured_data'] ?? []) as $entity) $structuredEntities[hash('sha256', json_encode($entity))] = $entity;
            if ($index > 0 && ! empty($page['name']) && (empty($merged['name']) || empty($merged['name_is_structured']) && ! empty($page['name_is_structured']))) {
                $merged['name'] = $page['name']; $merged['name_is_structured'] = $page['name_is_structured'] ?? false;
            }
            foreach (['legal_name', 'address', 'city', 'country'] as $field) if (empty($merged[$field]) && ! empty($page[$field])) $merged[$field] = $page[$field];
            if (! empty($page['telephone'])) $phones[] = $page['telephone'];
            if (! empty($page['email'])) $emails[] = $page['email'];
            if (! empty($page['description'])) $descriptions[] = $page['description'];
        }
        if (empty($merged['name']) && ! empty($seed['organization_name'])) $merged['name'] = $seed['organization_name'];
        foreach (['telephone' => $phones, 'email' => $emails] as $field => $values) if ($values !== []) $merged[$field] = array_values(array_unique($values));
        if (count($structuredEntities) > 0) $merged['structured_data'] = array_slice(array_values($structuredEntities), 0, 10);
        if ($descriptions !== [] && empty($merged['description'])) $merged['description'] = $descriptions[0];
        if (count($pages) > 1) $merged['text_excerpt'] = mb_substr(implode(' ', array_filter(array_map(fn ($page) => $page['text_excerpt'] ?? '', $pages))), 0, 4000);
        return $merged;
    }

    /** @return array{url:string,html:string} */
    private function fetchPage(string $url, string $domain): array
    {
        try { $this->policy->validatePublicHttpUrl($url); }
        catch (Throwable $error) {
            if (str_contains(mb_strtolower($error->getMessage()), 'resolve')) throw new \RuntimeException('DNS_FAILURE', previous: $error);
            throw new \InvalidArgumentException('UNSAFE_URL', previous: $error);
        }
        $this->assertRobotsAllows($url, $domain);
        try { $response = $this->policy->fetch($url, timeoutSeconds: 10); }
        catch (Throwable $error) { throw new \RuntimeException($this->failureCode($error), previous: $error); }
        if (! $response->successful()) throw new \RuntimeException('HTTP_FAILURE');
        if (! str_contains(mb_strtolower((string) $response->header('Content-Type')), 'text/html')) throw new \RuntimeException('NON_HTML');
        if (strlen($response->body()) > (int) config('website_resolution.max_candidate_page_bytes', 1_000_000)) throw new \RuntimeException('OVERSIZED');
        return ['url' => $url, 'html' => $response->body()];
    }

    private function assertRobotsAllows(string $url, string $domain): void
    {
        $parts = parse_url($url);
        $robotsKey = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower($domain);
        $robotsUrl = ($parts['scheme'] ?? 'https').'://'.$domain.'/robots.txt';
        if (! array_key_exists($robotsKey, $this->robotsBodies)) {
            try { $response = $this->policy->fetch($robotsUrl, timeoutSeconds: 8); }
            catch (Throwable $error) { throw new \RuntimeException('ROBOTS_'.$this->failureCode($error), previous: $error); }
            if (! in_array($response->status(), [404, 410], true) && ! $response->successful()) throw new \RuntimeException('ROBOTS_UNAVAILABLE');
            $this->robotsBodies[$robotsKey] = $response->body();
        }
        if (! $this->robots->allows($this->robotsBodies[$robotsKey], (string) (parse_url($url, PHP_URL_PATH) ?: '/'))) throw new \RuntimeException('ROBOTS_DENIED');
    }

    private function homepageUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return $url;
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$port.'/';
    }

    private function failureCode(Throwable $error): string
    {
        $message = mb_strtolower($error->getMessage());
        if (str_contains($message, 'redirect') || str_contains($message, 'downgrade')) return 'UNSAFE_REDIRECT';
        if (str_contains($message, 'resolve') || str_contains($message, 'dns')) return 'DNS_FAILURE';
        if (str_contains($message, 'timeout')) return 'TIMEOUT';
        if (str_contains($message, 'response exceeds')) return 'OVERSIZED';
        if ($error instanceof \InvalidArgumentException) return 'UNSAFE_URL';
        return 'TRANSPORT_FAILURE';
    }
}
