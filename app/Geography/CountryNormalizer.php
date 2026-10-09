<?php

namespace App\Geography;

/** Normalizes country names and ISO codes to ISO 3166-1 alpha-2 codes. */
final class CountryNormalizer
{
    /** @var array<string, string> */
    private const ALIASES = [
        'uae' => 'AE', 'are' => 'AE', 'united arab emirates' => 'AE',
        'india' => 'IN', 'ind' => 'IN',
    ];

    public function normalize(string $country): ?string
    {
        $country = mb_strtolower(trim(preg_replace('/\s+/', ' ', $country) ?? $country));
        if ($country === '') return null;
        if (isset(self::ALIASES[$country])) return self::ALIASES[$country];

        if (! class_exists(\ResourceBundle::class)) return null;
        $countries = \ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');
        if (! $countries) return null;

        $code = strtoupper($country);
        if (strlen($code) === 2 && $countries->get($code) !== null) return $code;

        foreach ($countries as $isoCode => $displayName) {
            if (is_string($displayName) && mb_strtolower(trim($displayName)) === $country && preg_match('/^[A-Z]{2}$/', (string) $isoCode)) {
                return (string) $isoCode;
            }
        }

        return null;
    }
}
