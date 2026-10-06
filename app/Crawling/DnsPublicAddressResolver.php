<?php

namespace App\Crawling;

use InvalidArgumentException;
use RuntimeException;

final class DnsPublicAddressResolver implements PublicAddressResolverInterface
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false || $records === []) {
                throw new RuntimeException('The URL host could not be resolved.');
            }
            $addresses = array_values(array_filter(array_map(
                static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                $records,
            )));
        }

        if ($addresses === []) {
            throw new RuntimeException('The URL host did not resolve to an IP address.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('The URL resolves to a non-public address.');
            }
        }

        return $addresses;
    }
}
