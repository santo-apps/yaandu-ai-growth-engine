<?php

namespace Tests\Unit;

use App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebsiteIntelligenceFailureTaxonomyTest extends TestCase
{
    public function test_crawler_failures_map_to_stable_categories_without_using_error_text_as_client_message(): void
    {
        $taxonomy = new WebsiteIntelligenceFailureTaxonomy();
        self::assertSame('DNS', $taxonomy->classify(new RuntimeException('The URL host could not be resolved.')));
        self::assertSame('HTTP_4XX', $taxonomy->classify(new RuntimeException('Website returned HTTP 404.')));
        self::assertSame('HTTP_5XX', $taxonomy->classify(new RuntimeException('Website returned HTTP 503.')));
        self::assertSame('REDIRECT_POLICY', $taxonomy->classify(new RuntimeException('Cross-domain redirects are not allowed.')));
        self::assertSame('SSRF_POLICY', $taxonomy->classify(new RuntimeException('The URL resolves to a non-public address.')));
        self::assertSame('TIMEOUT', $taxonomy->classify(new RuntimeException('Crawl budget timeout reached.')));
        self::assertSame('PROVIDER_AUTH', $taxonomy->classifyProvider(new RuntimeException('AI provider request failed with HTTP 401')));
        self::assertSame('PROVIDER_RATE_LIMIT', $taxonomy->classifyProvider(new RuntimeException('HTTP 429 rate limit')));
    }

    public function test_retryability_is_explicit_and_unknown_history_stays_unknown(): void
    {
        $taxonomy = new WebsiteIntelligenceFailureTaxonomy();
        self::assertSame('YES', $taxonomy->retryability('TIMEOUT'));
        self::assertSame('NO', $taxonomy->retryability('SSRF_POLICY'));
        self::assertSame('UNKNOWN', $taxonomy->retryability('UNKNOWN'));
        self::assertSame('The website scan could not be completed; available diagnostics are inconclusive.', $taxonomy->safeSummary('UNKNOWN'));
    }
}
