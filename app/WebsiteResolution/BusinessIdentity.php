<?php

namespace App\WebsiteResolution;

final readonly class BusinessIdentity
{
    public function __construct(
        public string $businessName,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $address = null,
        public ?string $postcode = null,
        public ?string $category = null,
        public ?string $brand = null,
        public ?string $operator = null,
        public ?string $publicPhone = null,
        public ?string $publicEmail = null,
        public array $alternateNames = [],
        public array $sourceProvenance = [],
    ) {}

    public static function fromArray(array $identity): self
    {
        return new self(
            businessName: mb_substr(trim((string) ($identity['business_name'] ?? '')), 0, 255),
            city: self::nullable($identity['city'] ?? null, 120),
            region: self::nullable($identity['region'] ?? null, 120),
            country: self::nullable($identity['country'] ?? null, 100),
            address: self::nullable($identity['address'] ?? null, 500),
            postcode: self::nullable($identity['postcode'] ?? null, 32),
            category: self::nullable($identity['category'] ?? $identity['industry'] ?? null, 150),
            brand: self::nullable($identity['brand'] ?? null, 255),
            operator: self::nullable($identity['operator'] ?? null, 255),
            publicPhone: self::nullable($identity['public_phone'] ?? null, 64),
            publicEmail: self::nullable($identity['public_email'] ?? null, 254),
            alternateNames: array_slice(array_values(array_filter(array_map(fn ($value) => self::nullable($value, 255), (array) ($identity['alternate_names'] ?? [])))), 0, 5),
            sourceProvenance: array_slice((array) ($identity['source_provenance'] ?? []), 0, 10),
        );
    }

    public function toArray(): array
    {
        return ['business_name' => $this->businessName, 'city' => $this->city, 'region' => $this->region,
            'country' => $this->country, 'address' => $this->address, 'postcode' => $this->postcode,
            'category' => $this->category, 'brand' => $this->brand, 'operator' => $this->operator,
            'public_phone' => $this->publicPhone, 'public_email' => $this->publicEmail,
            'alternate_names' => $this->alternateNames, 'source_provenance' => $this->sourceProvenance];
    }

    public function hasMinimumDiscoveryQuality(): bool
    {
        $normalizer = app(BusinessIdentityNormalizer::class);
        return mb_strlen($normalizer->name($this->businessName)) >= (int) config('candidate_discovery.minimum_name_length', 3)
            && (($this->city && $this->country) || $this->country || $this->city
                || ($this->address && ($this->category || $this->postcode))
                || ($this->category && $this->region));
    }

    private static function nullable(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
