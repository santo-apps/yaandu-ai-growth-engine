<?php

namespace Tests\Unit;

use App\WebsiteResolution\WebsiteIdentityPageExtractor;
use PHPUnit\Framework\TestCase;

class WebsiteIdentityPageExtractorTest extends TestCase
{
    public function test_only_explicit_organization_json_ld_counts_as_structured_evidence(): void
    {
        $extractor = new WebsiteIdentityPageExtractor;

        $plainPage = $extractor->extract('<title>Harbor Works</title><h1>Harbor Works</h1>', 'https://harbor.example/');
        self::assertSame([], $plainPage['structured_data']);

        $structuredPage = $extractor->extract(<<<'HTML'
            <script type="application/ld+json">
            {"@type":"Organization","name":"Harbor Works","legalName":"Harbor Works Ltd","url":"https://harbor.example/","address":{"@type":"PostalAddress","addressLocality":"Kochi","addressCountry":"India"}}
            </script>
            HTML, 'https://harbor.example/');

        self::assertCount(1, $structuredPage['structured_data']);
        self::assertSame('Harbor Works Ltd', $structuredPage['structured_data'][0]['legal_name']);
        self::assertSame('Kochi', $structuredPage['structured_data'][0]['city']);
    }

    public function test_identity_link_discovery_is_limited_to_explicit_about_and_contact_labels(): void
    {
        $links = (new WebsiteIdentityPageExtractor)->identityLinks(<<<'HTML'
            <a href="/contact">Contact Us</a>
            <a href="about/">About our company</a>
            <a href="/products">Products</a>
            <a href="mailto:info@example.com">Email</a>
            HTML, 'https://identity.example/');

        self::assertSame([
            ['url' => 'https://identity.example/contact', 'type' => 'contact'],
            ['url' => 'https://identity.example/about/', 'type' => 'about'],
        ], $links);
    }
}
