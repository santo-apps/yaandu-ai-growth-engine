<?php

namespace App\WebsiteResolution;

use App\Discovery\DomainNormalizer;
use Generator;
use RuntimeException;

/** Streams a locally staged Common Crawl WARC artifact. Acquisition is deliberately separate. */
final class CommonCrawlArtifactProcessor
{
    private array $stats = ['records_read' => 0, 'records_skipped' => 0, 'bytes_uncompressed' => 0, 'record_budget_exhausted' => 0];
    public function __construct(private readonly DomainNormalizer $domains, private readonly WebsiteIdentityPageExtractor $extractor) {}

    /** @return Generator<int,array{record:int,url:string,date:?string,mime:string,html:string}> */
    public function records(string $path, array $budget, int $afterRecord = 0): Generator
    {
        $this->stats = ['records_read' => 0, 'records_skipped' => 0, 'bytes_uncompressed' => 0, 'record_budget_exhausted' => 0];
        $maxArtifactBytes = min(2_000_000_000, max(1024, (int) ($budget['max_artifact_bytes'] ?? 50_000_000)));
        $maxRecordBytes = min(10_000_000, max(1024, (int) ($budget['max_record_bytes'] ?? 2_000_000)));
        $maxUncompressedBytes = min(2_000_000_000, max(1024, (int) ($budget['max_uncompressed_bytes'] ?? 100_000_000)));
        $maxRecords = min(100_000, max(1, (int) ($budget['max_records'] ?? 5000)));
        $maxRuntime = min(3600, max(1, (int) ($budget['max_runtime_seconds'] ?? 300)));
        if (! is_file($path) || ! is_readable($path) || filesize($path) > $maxArtifactBytes) throw new RuntimeException('Artifact is missing, unreadable, or exceeds its byte budget.');
        if (! str_ends_with(strtolower($path), '.warc.gz')) throw new RuntimeException('Only gzip-compressed WARC artifacts are supported.');
        $stream = @gzopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('WARC artifact could not be opened.');
        $started = microtime(true); $recordNo = 0; $seen = 0;
        try {
            while (! gzeof($stream) && $seen < $maxRecords && $this->stats['bytes_uncompressed'] < $maxUncompressedBytes && microtime(true) - $started < $maxRuntime) {
                $line = $this->line($stream, 8192);
                if ($line === null) break;
                if (! str_starts_with($line, 'WARC/')) continue;
                $header = []; $headerBytes = strlen($line); $oversized = false;
                while (($line = $this->line($stream, 8192)) !== null && trim($line) !== '') {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 32768) { $oversized = true; break; }
                    if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', trim($line), $m)) $header[strtolower($m[1])] = trim($m[2]);
                }
                if ($oversized) throw new RuntimeException('WARC header exceeded its limit.');
                $length = filter_var($header['content-length'] ?? null, FILTER_VALIDATE_INT);
                if ($length === false || $length < 0) throw new RuntimeException('WARC record has an invalid content length.');
                if ($length > $maxRecordBytes) {
                    if ($this->stats['bytes_uncompressed'] + $length > $maxUncompressedBytes) break;
                    $this->discard($stream, $length); $recordNo++; $seen++;
                    $this->stats['records_read']++; $this->stats['records_skipped']++; $this->stats['bytes_uncompressed'] += $length;
                    if ($recordNo > $afterRecord) yield ['record' => $recordNo, 'url' => (string) ($header['warc-target-uri'] ?? ''),
                        'date' => $header['warc-date'] ?? null, 'mime' => '', 'html' => null];
                    continue;
                }
                if ($this->stats['bytes_uncompressed'] + $length > $maxUncompressedBytes) break;
                $payload = ''; $remaining = $length;
                while ($remaining > 0 && ! gzeof($stream)) {
                    $chunk = gzread($stream, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') break;
                    $payload .= $chunk; $remaining -= strlen($chunk);
                }
                $this->line($stream, 2); // WARC record separator
                if ($remaining !== 0) break;
                $recordNo++; $seen++;
                $this->stats['records_read']++;
                $this->stats['bytes_uncompressed'] += $length;
                $url = (string) ($header['warc-target-uri'] ?? '');
                if ($recordNo <= $afterRecord) continue;
                if (($header['warc-type'] ?? '') !== 'response' || $url === '') {
                    $this->stats['records_skipped']++;
                    yield ['record' => $recordNo, 'url' => $url, 'date' => $header['warc-date'] ?? null, 'mime' => '', 'html' => null];
                    continue;
                }
                $httpEnd = strpos($payload, "\r\n\r\n");
                if ($httpEnd === false) {
                    $this->stats['records_skipped']++;
                    yield ['record' => $recordNo, 'url' => $url, 'date' => $header['warc-date'] ?? null, 'mime' => '', 'html' => null];
                    continue;
                }
                $httpHeaders = substr($payload, 0, $httpEnd); $body = substr($payload, $httpEnd + 4);
                if (! preg_match('/^HTTP\/\d(?:\.\d)?\s+200\b/m', $httpHeaders) || ! preg_match('/^content-type:\s*text\/html\b/im', $httpHeaders)
                    || strlen($body) > min(1_000_000, (int) ($budget['max_document_bytes'] ?? 250_000))) {
                    $this->stats['records_skipped']++;
                    yield ['record' => $recordNo, 'url' => $url, 'date' => $header['warc-date'] ?? null, 'mime' => '', 'html' => null];
                    continue;
                }
                yield ['record' => $recordNo, 'url' => $url, 'date' => $header['warc-date'] ?? null,
                    'mime' => 'text/html', 'html' => $body];
            }
            if ($seen >= $maxRecords && ! gzeof($stream)) $this->stats['record_budget_exhausted'] = 1;
        } finally { gzclose($stream); }
    }

    public function stats(): array { return $this->stats; }

    /** Convert one archived page into index input only when deterministic business evidence exists. */
    public function document(array $record, string $artifactIdentity, string $crawlId): ?array
    {
        try {
            $parts = parse_url((string) $record['url']);
            if (! is_array($parts) || filter_var((string) ($parts['host'] ?? ''), FILTER_VALIDATE_IP)) return null;
            $normalized = $this->domains->normalize((string) $record['url']);
        } catch (\Throwable) { return null; }
        $identity = $this->extractor->extract((string) $record['html'], $normalized['normalized_url']);
        $text = mb_strtolower((string) ($identity['text_excerpt'] ?? ''));
        if (preg_match('/\b(domain for sale|buy this domain|parked free|account suspended|404 not found|page not found|search results)\b/u', $text)) return null;
        $structured = (array) ($identity['structured_data'] ?? []);
        $phones = array_values(array_unique(array_filter(array_map('strval', (array) ($identity['telephone'] ?? [])))));
        $emails = array_values(array_unique(array_filter(array_map('strval', (array) ($identity['email'] ?? [])))));
        $hasOrg = ! empty($identity['name_is_structured']) && $structured !== [];
        $hasContact = $phones !== [] || $emails !== [];
        $hasBusinessText = (bool) preg_match('/\b(company|business|services|products|our team|contact us|about us|shop|clinic|restaurant|manufacturer|consulting)\b/u', $text);
        $hasLocation = ! empty($identity['city']) || ! empty($identity['country']) || ! empty($identity['address']);
        if (! $hasOrg && ! ($hasContact && $hasBusinessText) && ! ($hasLocation && $hasBusinessText && ! empty($identity['name']))) return null;
        $reference = 'commoncrawl:'.$crawlId.':'.$artifactIdentity.':record-'.$record['record'];
        return ['canonical_url' => $normalized['normalized_url'], 'normalized_domain' => $normalized['normalized_domain'],
            'page_title' => $identity['title'] ?? null, 'organization_name' => $identity['name'] ?? null,
            'description' => $identity['description'] ?? null, 'visible_text_excerpt' => mb_substr((string) ($identity['text_excerpt'] ?? ''), 0, 4000),
            'country' => $identity['country'] ?? null, 'city' => $identity['city'] ?? null, 'address_text' => $identity['address'] ?? null,
            'phone_values' => $phones, 'email_values' => $emails,
            'structured_data' => $structured ? ['source_url' => $normalized['normalized_url'], 'entities' => $structured] : [],
            'source' => 'common_crawl_offline_artifact', 'source_reference' => $reference,
            'source_timestamp' => $record['date'] ?? null, 'evidence_type' => 'common_crawl_archived_page',
            'source_query' => ['crawl_id' => $crawlId, 'artifact' => $artifactIdentity, 'record' => $record['record'], 'original_url' => $record['url'], 'capture_timestamp' => $record['date'] ?? null],
            '_bytes' => strlen((string) $record['html']), '_cursor' => ['artifact' => $artifactIdentity, 'record' => (int) $record['record']]];
    }

    private function line($stream, int $limit): ?string
    {
        $line = gzgets($stream, $limit + 1);
        if ($line === false) return null;
        if (strlen($line) > $limit && ! str_ends_with($line, "\n")) throw new RuntimeException('WARC line exceeded its limit.');
        return $line;
    }

    private function discard($stream, int $length): void
    {
        while ($length > 0 && ! gzeof($stream)) { $chunk = gzread($stream, min(65536, $length)); if ($chunk === false || $chunk === '') break; $length -= strlen($chunk); }
        $this->line($stream, 2);
    }
}
