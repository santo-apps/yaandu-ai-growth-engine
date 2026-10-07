<?php

namespace Tests\Unit;

use App\WebsiteResolution\DirectoryDomainClassifier;
use App\WebsiteResolution\IndexQualityClassifier;
use PHPUnit\Framework\TestCase;

class IndexQualityClassifierTest extends TestCase
{
    private function classifier(): IndexQualityClassifier
    {
        return new IndexQualityClassifier(new DirectoryDomainClassifier);
    }

    public function test_business_quality_requires_name_location_and_useful_public_evidence(): void
    {
        $document = (object) ['canonical_url' => 'https://harbor.example.com/', 'normalized_domain' => 'harbor.example.com',
            'document_type' => 'business', 'organization_name' => 'Harbor Works', 'city' => 'Kochi', 'country' => 'India',
            'availability' => 'available', 'has_public_contact' => true, 'has_structured_data' => false];
        self::assertSame('HIGH_QUALITY_BUSINESS', $this->classifier()->classify($document));
        $document->has_public_contact = false;
        self::assertSame('PARTIAL_BUSINESS_EVIDENCE', $this->classifier()->classify($document));
    }

    public function test_classification_excludes_directory_brand_academic_suspended_and_error_entries(): void
    {
        $classifier = $this->classifier();
        $base = ['document_type' => 'business', 'organization_name' => 'Example Business', 'city' => 'Dubai', 'country' => 'UAE',
            'availability' => 'available', 'has_public_contact' => true, 'has_structured_data' => true];
        self::assertSame('DIRECTORY', $classifier->classify((object) [...$base, 'canonical_url' => 'https://facebook.com/example']));
        self::assertSame('BRAND_ONLY', $classifier->classify((object) [...$base, 'canonical_url' => 'https://brand.example.com/'], ['brand_entity']));
        self::assertSame('ACADEMIC_SUBUNIT', $classifier->classify((object) [...$base, 'canonical_url' => 'https://chemistry.iisc.ac.in/', 'normalized_domain' => 'chemistry.iisc.ac.in',
            'organization_name' => 'Department of Chemistry']));
        self::assertSame('SUSPENDED', $classifier->classify((object) [...$base, 'canonical_url' => 'https://paused.example.com/', 'page_title' => 'Account Suspended']));
        self::assertSame('ERROR_PAGE', $classifier->classify((object) [...$base, 'canonical_url' => 'https://parked.example.com/', 'page_title' => 'Domain for sale']));
        self::assertSame('OTHER_LOW_VALUE', $classifier->classify((object) ['canonical_url' => 'https://unknown.example.com/', 'document_type' => 'business']));
    }
}
