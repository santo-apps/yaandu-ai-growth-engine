<?php

namespace App\WebsiteResolution;

final class BusinessWebsiteIdentityMatcher
{
    public function evaluate(array $identity, array $site, array $sourceEvidence, string $domain): array
    {
        $items = [];
        foreach ($sourceEvidence as $evidence) {
            $items[] = ['signal' => $evidence['signal'], 'polarity' => $evidence['polarity'], 'points' => (int) $evidence['points'],
                'summary' => $evidence['summary'], 'source' => $evidence['source'], 'details' => $evidence['details'] ?? []];
        }
        $normalizer = app(BusinessIdentityNormalizer::class);
        $names = array_filter([$identity['business_name'] ?? null, $identity['brand'] ?? null, $identity['operator'] ?? null]);
        $siteNames = array_filter([$site['name'] ?? null, $site['legal_name'] ?? null, $site['title'] ?? null]);
        foreach ($names as $name) {
            foreach ($siteNames as $siteName) {
                if ($normalizer->name((string) $name) !== '' && $normalizer->name((string) $name) === $normalizer->name((string) $siteName)) {
                    $items[] = $this->signal('name_match', 20, 'positive', 'The public website name matches the business identity.', ['site_name' => $siteName]);
                    break 2;
                }
            }
        }
        $identityPhone = $this->digits((string) ($identity['public_phone'] ?? ''));
        $sitePhone = $this->digits((string) ($site['telephone'] ?? ''));
        if (strlen($identityPhone) >= 7 && $identityPhone === $sitePhone) $items[] = $this->signal('phone_match', 45, 'positive', 'The public phone number exactly matches.', []);

        foreach ($sourceEvidence as $evidence) {
            if (($evidence['signal'] ?? null) !== 'directory_business_link') continue;
            $details = (array) ($evidence['details'] ?? []);
            if ($normalizer->name((string) ($identity['business_name'] ?? '')) !== ''
                && $normalizer->name((string) ($identity['business_name'] ?? '')) === $normalizer->name((string) ($details['linked_name'] ?? ''))) {
                $items[] = $this->signal('directory_name_match', 20, 'positive', 'A public directory links the matching business name to this candidate URL.', ['directory_url' => $details['directory_url'] ?? null]);
            }
            if (($identityCity = $normalizer->text($identity['city'] ?? null)) !== '' && $identityCity === $normalizer->text($details['linked_city'] ?? null)) {
                $items[] = $this->signal('directory_location_match', 15, 'positive', 'The public directory listing corroborates the business location.', ['directory_url' => $details['directory_url'] ?? null]);
            }
            $directoryPhone = $this->digits((string) ($details['linked_phone'] ?? ''));
            if (strlen($identityPhone) >= 7 && $identityPhone === $directoryPhone) $items[] = $this->signal('directory_phone_match', 30, 'positive', 'The public directory listing corroborates the exact business phone.', ['directory_url' => $details['directory_url'] ?? null]);
            $identityCountry = $normalizer->country($identity['country'] ?? null);
            $directoryCountry = $normalizer->country($details['linked_country'] ?? null);
            if ($identityCountry !== '' && $directoryCountry !== '' && $identityCountry !== $directoryCountry) {
                $items[] = $this->signal('directory_country_contradiction', -35, 'negative', 'The directory listing location conflicts with the business country.', ['directory_url' => $details['directory_url'] ?? null]);
            }
        }
        $identityEmail = mb_strtolower(trim((string) ($identity['public_email'] ?? '')));
        $siteEmail = mb_strtolower(trim((string) ($site['email'] ?? '')));
        if ($identityEmail !== '' && $identityEmail === $siteEmail) $items[] = $this->signal('email_match', 50, 'positive', 'The public business email exactly matches.', []);

        $identityCity = $this->normalize((string) ($identity['city'] ?? ''));
        $siteAddress = $this->normalize(implode(' ', array_filter([(string) ($site['address'] ?? ''), (string) ($site['city'] ?? ''), (string) ($site['country'] ?? '')])));
        if ($identityCity !== '' && str_contains($siteAddress, $identityCity)) $items[] = $this->signal('location_match', 12, 'positive', 'The website structured address includes the business city.', ['city' => $identity['city']]);
        $identityAddress = $this->normalize((string) ($identity['address'] ?? ''));
        if ($identityAddress !== '' && str_contains($siteAddress, $identityAddress)) $items[] = $this->signal('address_match', 20, 'positive', 'The website structured address matches the source address.', []);
        $siteCountry = $normalizer->country((string) ($site['country'] ?? ''));
        $identityCountry = $normalizer->country((string) ($identity['country'] ?? ''));
        if ($siteCountry !== '' && $identityCountry !== '' && $siteCountry !== $identityCountry) {
            $items[] = $this->signal('country_contradiction', -35, 'negative', 'The structured website country conflicts with the business location.', ['website_country' => $site['country']]);
        }
        $siteUrl = strtolower((string) parse_url((string) ($site['url'] ?? ''), PHP_URL_HOST));
        if ($siteUrl !== '' && ($siteUrl === $domain || str_ends_with($siteUrl, '.'.$domain))) $items[] = $this->signal('structured_data_match', 15, 'positive', 'Public structured Organization data names this website domain.', ['structured_url' => $site['url']]);
        foreach (array_slice((array) ($site['same_as'] ?? []), 0, 10) as $link) {
            if (in_array((string) $link, (array) ($identity['public_urls'] ?? []), true)) {
                $items[] = $this->signal('social_corroboration', 8, 'positive', 'The organization structured data links to a public identity URL.', ['url' => $link]); break;
            }
        }
        if (preg_match('/domain (is )?for sale|buy this domain|parked domain|this domain may be for sale|sedo domain parking/', mb_strtolower((string) ($site['text_excerpt'] ?? '')))) {
            $items[] = $this->signal('parked_domain', -80, 'negative', 'The page appears to be parked or offered for sale.', []);
        }
        if (preg_match('/^[^@\\s]+@([^@\\s]+)$/', $identityEmail, $emailParts) && strtolower($emailParts[1]) === strtolower($domain)) {
            $items[] = $this->signal('email_domain_match', 20, 'positive', 'The public business email uses the candidate website domain.', []);
        }
        $index = (array) config('website_resolution.score', []);
        $sum = 0;
        foreach ($items as &$item) {
            if ($item['source'] === 'matcher') $item['points'] = (int) ($index[$item['signal']] ?? $item['points']);
            $sum += $item['points'];
        }
        unset($item);
        $score = min(100, max(0, $sum));
        $high = (int) config('website_resolution.high_confidence_threshold', 85);
        $medium = (int) config('website_resolution.medium_confidence_threshold', 50);
        $band = $score >= $high ? 'HIGH' : ($score >= $medium ? 'MEDIUM' : 'LOW');
        return ['domain' => $domain, 'score' => $score, 'confidence_band' => $band, 'evidence' => $items,
            'positive_evidence' => array_values(array_filter($items, fn ($item) => $item['polarity'] === 'positive')),
            'negative_evidence' => array_values(array_filter($items, fn ($item) => $item['polarity'] === 'negative')),
            'site' => $site];
    }

    private function signal(string $signal, int $points, string $polarity, string $summary, array $details): array
    { return ['signal' => $signal, 'polarity' => $polarity, 'points' => $points, 'summary' => $summary, 'source' => 'matcher', 'details' => $details]; }
    private function normalize(string $value): string { return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? ''); }
    private function digits(string $value): string { return preg_replace('/\D+/', '', $value) ?? ''; }
    private function sameCountry(string $left, string $right): bool
    { return (in_array($left, ['united arab emirates', 'uae'], true) && in_array($right, ['united arab emirates', 'uae'], true)) || (in_array($left, ['united states', 'usa', 'us'], true) && in_array($right, ['united states', 'usa', 'us'], true)); }
}
