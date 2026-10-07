<?php

namespace App\WebsiteResolution;

final class BusinessIdentityNormalizer
{
    private const LEGAL_SUFFIXES = ['llc', 'l l c', 'ltd', 'limited', 'inc', 'incorporated', 'corp', 'corporation', 'plc', 'gmbh', 'llp', 'fze', 'fzco', 'pjsc'];

    public function text(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public function name(?string $value): string
    {
        $normalized = $this->text($value);
        $tokens = explode(' ', $normalized);
        if (count($tokens) >= 3 && in_array(implode(' ', array_slice($tokens, -3)), ['l l c', 'l l p'], true)) $tokens = array_slice($tokens, 0, -3);
        while ($tokens !== [] && in_array(end($tokens), self::LEGAL_SUFFIXES, true)) array_pop($tokens);
        return implode(' ', $tokens);
    }

    public function country(?string $value): string
    {
        $country = $this->text($value);
        return match ($country) {
            'uae', 'u a e', 'united arab emirates' => 'united arab emirates',
            'uk', 'u k', 'great britain', 'britain', 'united kingdom' => 'united kingdom',
            'us', 'u s', 'usa', 'u s a', 'united states of america', 'united states' => 'united states',
            default => $country,
        };
    }

    public function phone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public function domain(?string $value): string
    {
        $host = mb_strtolower(trim((string) parse_url(str_contains((string) $value, '://') ? $value : 'https://'.$value, PHP_URL_HOST), '.'));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function tokens(?string $value): array
    {
        return array_values(array_unique(array_filter(explode(' ', $this->text($value)), fn (string $token) => mb_strlen($token) > 1)));
    }
}
