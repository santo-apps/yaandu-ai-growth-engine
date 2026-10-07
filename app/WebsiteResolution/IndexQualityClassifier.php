<?php

namespace App\WebsiteResolution;

final class IndexQualityClassifier
{
    public const CLASSES = ['HIGH_QUALITY_BUSINESS', 'PARTIAL_BUSINESS_EVIDENCE', 'BRAND_ONLY', 'DIRECTORY',
        'ACADEMIC_SUBUNIT', 'SUSPENDED', 'ERROR_PAGE', 'OTHER_LOW_VALUE'];

    public function __construct(private readonly DirectoryDomainClassifier $directories) {}

    public function classify(object|array $document, array $evidenceTypes = []): string
    {
        $get = static fn (string $key): mixed => is_array($document) ? ($document[$key] ?? null) : ($document->{$key} ?? null);
        $url = (string) ($get('canonical_url') ?? '');
        $type = (string) ($get('document_type') ?? 'business');
        if ($type === 'directory' || $this->directories->classify($url) !== null) return 'DIRECTORY';

        $text = mb_strtolower(implode(' ', array_filter([(string) ($get('page_title') ?? ''), (string) ($get('description') ?? ''), (string) ($get('visible_text_excerpt') ?? '')])));
        if (preg_match('/\b(account suspended|domain suspended|website suspended|hosting suspended)\b/u', $text)) return 'SUSPENDED';
        if (preg_match('/\b(404 not found|page not found|site not found|access denied|default web site|parking page|domain for sale)\b/u', $text)) return 'ERROR_PAGE';

        $evidenceTypes = array_values(array_filter($evidenceTypes, static fn ($value): bool => is_string($value) && ! in_array($value, ['refresh_observation', 'legacy_import'], true)));
        if ($evidenceTypes !== [] && count(array_filter($evidenceTypes, static fn (string $value): bool => str_starts_with($value, 'brand_'))) === count($evidenceTypes)) {
            return 'BRAND_ONLY';
        }

        $name = trim((string) ($get('organization_name') ?? ''));
        $location = trim((string) ($get('city') ?? '')) !== '' || trim((string) ($get('country') ?? '')) !== '';
        $domain = strtolower((string) ($get('normalized_domain') ?? ''));
        $academicSubdomain = str_ends_with($domain, '.ac.in') && count(explode('.', $domain)) >= 4;
        $academicText = mb_strtolower($name.' '.(string) ($get('page_title') ?? '').' '.(string) ($get('description') ?? '').' '.(string) ($get('visible_text_excerpt') ?? ''));
        if (($academicSubdomain || preg_match('/\b(department|faculty|laborator(?:y|ies)|research (?:group|centre|center)|school of|college of)\b/u', $academicText))
            && (str_contains($domain, '.ac.') || str_ends_with($domain, '.edu') || str_ends_with($domain, '.edu.au'))) {
            return 'ACADEMIC_SUBUNIT';
        }

        $hasContact = (bool) ($get('has_public_contact') ?? false);
        $hasStructured = (bool) ($get('has_structured_data') ?? false);
        $hasUsefulDescription = trim((string) ($get('description') ?? '')) !== '' && mb_strlen(trim((string) ($get('visible_text_excerpt') ?? ''))) >= 100;
        $available = (string) ($get('availability') ?? 'available') === 'available';
        if ($name !== '' && $location && $available && ($hasContact || $hasStructured || $hasUsefulDescription)) return 'HIGH_QUALITY_BUSINESS';
        if ($name !== '' && $location) return 'PARTIAL_BUSINESS_EVIDENCE';

        return 'OTHER_LOW_VALUE';
    }
}
