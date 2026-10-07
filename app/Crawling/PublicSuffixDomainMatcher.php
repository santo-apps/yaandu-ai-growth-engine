<?php

namespace App\Crawling;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

final class PublicSuffixDomainMatcher
{
    private static ?Rules $rules = null;

    public function sameRegistrableDomain(string $firstHost, string $secondHost): bool
    {
        if (filter_var($firstHost, FILTER_VALIDATE_IP) || filter_var($secondHost, FILTER_VALIDATE_IP)) {
            return false;
        }

        try {
            $first = $this->registrableDomain($firstHost);
            $second = $this->registrableDomain($secondHost);

            return $first !== null && $second !== null && hash_equals($first, $second);
        } catch (Throwable) {
            return false;
        }
    }

    private function registrableDomain(string $host): ?string
    {
        $rules = self::$rules ??= Rules::fromPath(resource_path('data/public_suffix_list.dat'));
        $resolved = $rules->resolve(Domain::fromIDNA2008(strtolower($host)));
        $domain = $resolved->registrableDomain()->toString();

        if (! $resolved->suffix()->isKnown() || $domain === '') {
            return null;
        }

        return $domain;
    }
}
