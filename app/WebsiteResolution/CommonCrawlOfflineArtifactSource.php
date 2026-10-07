<?php

namespace App\WebsiteResolution;

use RuntimeException;

final class CommonCrawlOfflineArtifactSource implements WebIndexIngestionSourceInterface
{
    private array $counts = ['records_scanned' => 0, 'business_like_records' => 0, 'rejected_records' => 0, 'source_failures' => 0, 'bytes_processed' => 0];

    public function __construct(private readonly CommonCrawlArtifactProcessor $processor) {}
    public function name(): string { return 'common_crawl_offline_artifact'; }
    public function metrics(): array { return $this->counts; }

    public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
    {
        $path = (string) ($options['artifact_path'] ?? '');
        if ($path === '' || ! is_file($path)) throw new RuntimeException('A staged local WARC artifact is required.');
        $maxBytes = min(100_000_000, max(1024, (int) ($options['max_bytes'] ?? 50_000_000)));
        $artifactBytes = (int) filesize($path);
        if ($artifactBytes > min($maxBytes, (int) ($options['max_artifact_bytes'] ?? $maxBytes))) throw new RuntimeException('Staged artifact exceeds the configured byte budget.');
        $identity = hash_file('sha256', $path);
        if (! is_string($identity)) throw new RuntimeException('Artifact identity could not be calculated.');
        if (isset($cursor['artifact']) && ! hash_equals($identity, (string) $cursor['artifact'])) throw new RuntimeException('Resume artifact identity changed.');
        $recordStart = max(0, (int) ($cursor['record'] ?? 0));
        $maxRecords = min(100_000, max(1, (int) ($options['max_records'] ?? 5000)));
        $maxRuntime = min(3600, max(1, (int) ($options['max_runtime_seconds'] ?? 300)));
        $maxDomains = min(500, max(1, (int) ($options['max_domains'] ?? $limit)));
        $domains = []; $started = microtime(true);
        try {
            foreach ($this->processor->records($path, ['max_artifact_bytes' => $maxBytes, 'max_record_bytes' => (int) ($options['max_record_bytes'] ?? 2_000_000),
                'max_records' => $maxRecords, 'max_runtime_seconds' => $maxRuntime, 'max_document_bytes' => (int) ($options['max_document_bytes'] ?? 250_000),
                'max_uncompressed_bytes' => (int) ($options['max_uncompressed_bytes'] ?? $maxBytes)], $recordStart) as $record) {
                $cursorForRecord = ['artifact' => $identity, 'record' => (int) $record['record']];
                if (! is_string($record['html'] ?? null)) {
                    yield ['_skip' => true, '_cursor' => $cursorForRecord];
                    continue;
                }
                try { $document = $this->processor->document($record, $identity, (string) ($options['crawl_id'] ?? 'unspecified')); }
                catch (\Throwable) {
                    $this->counts['source_failures']++;
                    $this->counts['rejected_records']++;
                    yield ['_skip' => true, '_cursor' => $cursorForRecord];
                    if ($this->counts['source_failures'] >= max(1, (int) ($options['max_failures'] ?? 25))) break;
                    continue;
                }
                if ($document === null) {
                    $this->counts['rejected_records']++;
                    yield ['_skip' => true, '_cursor' => $cursorForRecord];
                    continue;
                }
                $this->counts['business_like_records']++;
                $document['source_query']['artifact_path'] = basename($path);
                $document['source_query']['artifact_identity'] = $identity;
                $document['source_reference'] = 'commoncrawl:'.(string) ($options['crawl_id'] ?? 'unspecified').':'.basename($path).':'.$identity.':record-'.$record['record'];
                $domain = (string) $document['normalized_domain'];
                if (! isset($domains[$domain])) {
                    if (count($domains) >= $maxDomains) break;
                    $domains[$domain] = true;
                }
                $document['_cursor'] = $cursorForRecord;
                yield $document;
                if (microtime(true) - $started >= $maxRuntime) break;
            }
        } catch (\Throwable $error) {
            $this->counts['source_failures']++;
            throw $error;
        } finally {
            $processorStats = $this->processor->stats();
            $this->counts['records_scanned'] += (int) ($processorStats['records_read'] ?? 0);
            $this->counts['rejected_records'] += (int) ($processorStats['records_skipped'] ?? 0);
            $this->counts['bytes_processed'] += (int) ($processorStats['bytes_uncompressed'] ?? 0);
            $this->counts['record_budget_exhausted'] = (int) ($processorStats['record_budget_exhausted'] ?? 0);
        }
    }
}
