<?php

namespace Tests\Unit;

use App\WebsiteResolution\CommonCrawlArtifactProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommonCrawlArtifactProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_streams_business_html_and_preserves_archive_provenance(): void
    {
        $path = $this->artifact([
            $this->warc('https://example.test/', '<html><head><title>Example Clinic</title><script type="application/ld+json">{"@type":"LocalBusiness","name":"Example Clinic","telephone":"+971500000000","address":{"@type":"PostalAddress","addressLocality":"Dubai","addressCountry":"AE"}}</script></head><body>Our clinic provides medical services. Contact us at care@example.test.</body></html>'),
            $this->warc('https://news.example.net/story', '<html><head><title>News article</title></head><body>A generic article with no company evidence.</body></html>'),
        ]);
        try {
            $processor = app(CommonCrawlArtifactProcessor::class);
            $records = iterator_to_array($processor->records($path, ['max_records' => 10, 'max_artifact_bytes' => 100_000, 'max_document_bytes' => 20_000]));
            self::assertCount(2, $records);
            $doc = $processor->document($records[0], hash_file('sha256', $path), 'CC-MAIN-2026-40');
            self::assertNotNull($doc);
            self::assertSame('example.test', $doc['normalized_domain']);
            self::assertSame('Dubai', $doc['city']);
            self::assertSame(['+971500000000'], $doc['phone_values']);
            self::assertSame('common_crawl_offline_artifact', $doc['source']);
            self::assertSame('https://example.test/', $doc['source_query']['original_url']);
            self::assertNull($processor->document($records[1], 'artifact', 'CC-MAIN-2026-40'));
        } finally { @unlink($path); }
    }

    public function test_record_resume_and_budget_rejection_are_bounded(): void
    {
        $path = $this->artifact([$this->warc('https://one.example.test/', '<html><title>One</title>'.bin2hex(random_bytes(800)).'</html>'), $this->warc('https://two.example.test/', '<html><title>Two</title>'.bin2hex(random_bytes(800)).'</html>')]);
        try {
            $processor = app(CommonCrawlArtifactProcessor::class);
            $rows = iterator_to_array($processor->records($path, ['max_records' => 10, 'max_artifact_bytes' => 100_000]));
            self::assertSame([1, 2], array_column($rows, 'record'));
            $resumed = iterator_to_array($processor->records($path, ['max_records' => 10, 'max_artifact_bytes' => 100_000], 1));
            self::assertSame([2], array_column($resumed, 'record'));
            $this->expectException(\RuntimeException::class);
            iterator_to_array($processor->records($path, ['max_records' => 10, 'max_artifact_bytes' => 1024]));
        } finally { @unlink($path); }
    }

    private function artifact(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cc-artifact-').'.warc.gz';
        file_put_contents($path, implode('', array_map('gzencode', $records)));
        return $path;
    }

    private function warc(string $url, string $html): string
    {
        $payload = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n\r\n".$html;
        return "WARC/1.0\r\nWARC-Type: response\r\nWARC-Target-URI: {$url}\r\nWARC-Date: 2026-09-01T00:00:00Z\r\nContent-Length: ".strlen($payload)."\r\n\r\n{$payload}\r\n\r\n";
    }
}
