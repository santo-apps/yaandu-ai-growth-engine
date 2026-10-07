<?php

namespace App\WebsiteResolution;

use App\Discovery\DomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class LocalWebIndexIngestionService
{
    public function __construct(private readonly BusinessIdentityNormalizer $normalizer, private readonly DomainNormalizer $domains) {}

    public function createRun(WebIndexIngestionSourceInterface $source, int $limit, array $options = []): string
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Index ingestion limit must be between 1 and 500.');
        $id = (string) Str::uuid();
        DB::table('web_index_ingestion_runs')->insert(['id' => $id, 'source' => $source->name(), 'requested_limit' => $limit,
            'options' => json_encode($options), 'cursor' => null, 'status' => 'pending', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function ingest(WebIndexIngestionSourceInterface $source, int $limit, array $options = [], ?string $resumeRunId = null): array
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Index ingestion limit must be between 1 and 500.');
        $run = null;
        if ($resumeRunId !== null) {
            $run = DB::table('web_index_ingestion_runs')->where('id', $resumeRunId)->first();
            if (! $run || $run->source !== $source->name() || ! in_array($run->status, ['pending', 'running', 'paused', 'partially_completed', 'failed'], true)) {
                throw new InvalidArgumentException('The ingestion run cannot be resumed.');
            }
            $storedOptions = is_array($run->options) ? $run->options : (json_decode((string) $run->options, true) ?: []);
            $options = array_merge($storedOptions, $options);
            $limit = max((int) $run->requested_limit, $limit);
            DB::table('web_index_ingestion_runs')->where('id', $resumeRunId)->update(['requested_limit' => $limit, 'options' => json_encode($options),
                'status' => 'running', 'resumed_at' => $run->status === 'pending' ? $run->resumed_at : now(), 'finished_at' => null, 'updated_at' => now()]);
        } else {
            $resumeRunId = $this->createRun($source, $limit, $options);
            DB::table('web_index_ingestion_runs')->where('id', $resumeRunId)->update(['status' => 'running']);
        }
        $run = DB::table('web_index_ingestion_runs')->where('id', $resumeRunId)->first();
        $cursor = is_array($run->cursor) ? $run->cursor : (json_decode((string) $run->cursor, true) ?: null);
        $processed = (int) $run->processed; $inserted = (int) $run->inserted; $updated = (int) $run->updated;
        $unchanged = (int) $run->unchanged; $failed = (int) $run->failed; $duplicates = (int) $run->duplicates;
        $uniqueDomainsAdded = (int) $run->unique_domains_added; $bytesProcessed = (int) $run->bytes_processed;
        $storedBytesDelta = (int) $run->stored_bytes_delta; $started = microtime(true); $partial = false;
        $priorMetrics = is_array($run->metrics) ? $run->metrics : (json_decode((string) $run->metrics, true) ?: []);
        $options['_resume_metrics'] = $priorMetrics;
        $options['_prior_processed'] = $processed;
        $sourceMetrics = [];

        try {
            foreach ($source->documents(max(1, $limit - $processed), $options, $cursor) as $input) {
                if ($processed >= $limit) { $partial = true; break; }
                $processed++;
                $inputCursor = (array) ($input['_cursor'] ?? $cursor ?? []);
                if (! empty($input['_skip'])) {
                    $processed--;
                    $cursor = $inputCursor ?: $cursor;
                    $sourceMetrics['records_skipped'] = ($sourceMetrics['records_skipped'] ?? 0) + 1;
                    $this->checkpoint($resumeRunId, $processed, $inserted, $updated, $unchanged, $failed, $duplicates, $uniqueDomainsAdded, $bytesProcessed, $storedBytesDelta, $cursor);
                    if (microtime(true) - $started >= max(10, (int) ($options['max_runtime_seconds'] ?? config('website_resolution.index_ingestion_max_duration_seconds', 300)))) { $partial = true; break; }
                    continue;
                }
                if (! empty($input['_failure'])) {
                    $code = (string) $input['_failure'];
                    $documentId = (string) ($input['_document_id'] ?? '');
                    if ($documentId !== '') DB::table('web_index_documents')->where('id', $documentId)->update([
                        'availability' => 'unavailable', 'last_fetched_at' => now(),
                        'next_refresh_at' => now()->addSeconds($this->refreshDelay($code)), 'updated_at' => now(),
                    ]);
                    $sourceMetrics['failure_'.$code] = ($sourceMetrics['failure_'.$code] ?? 0) + 1;
                    $cursor = $inputCursor ?: $cursor; $failed++;
                    $this->checkpoint($resumeRunId, $processed, $inserted, $updated, $unchanged, $failed, $duplicates, $uniqueDomainsAdded, $bytesProcessed, $storedBytesDelta, $cursor);
                    continue;
                }
                $inputBytes = max(0, (int) ($input['_bytes'] ?? 0));
                if ($bytesProcessed + $inputBytes > max(1024, (int) ($options['max_bytes'] ?? config('website_resolution.index_ingestion_max_bytes', 250_000_000)))) {
                    $processed--; $partial = true; break;
                }
                $bytesProcessed += $inputBytes;
                unset($input['_cursor'], $input['_bytes']);
                try {
                    $url = $this->domains->normalize((string) ($input['canonical_url'] ?? ''));
                    $name = trim((string) ($input['organization_name'] ?? ''));
                    if ($name === '') throw new InvalidArgumentException('Document has no supported business identity.');
                    $phones = array_values(array_filter((array) ($input['phone_values'] ?? []), fn ($value) => is_string($value) && $value !== ''));
                    $emails = array_values(array_filter((array) ($input['email_values'] ?? []), fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)));
                    $links = $this->normalizeLinks((array) ($input['outbound_business_links'] ?? []), $url['normalized_domain']);
                    $structured = $input['structured_data'] ?? [];
                    $identityPages = array_slice(array_values(array_filter((array) ($input['identity_pages'] ?? []), 'is_array')), 0, 3);
                    if ($identityPages !== []) $structured['identity_pages'] = $identityPages;
                    if (strlen((string) json_encode($structured)) > 32000) $structured = [];
                    $now = now();
                    $safe = [
                        'canonical_url' => $url['normalized_url'], 'normalized_domain' => $url['normalized_domain'],
                        'page_title' => mb_substr((string) ($input['page_title'] ?? ''), 0, 500) ?: null,
                        'organization_name' => mb_substr($name, 0, 255), 'description' => mb_substr((string) ($input['description'] ?? ''), 0, 2000) ?: null,
                        'visible_text_excerpt' => mb_substr((string) ($input['visible_text_excerpt'] ?? ''), 0, 4000) ?: null,
                        'country' => mb_substr((string) ($input['country'] ?? ''), 0, 100) ?: null, 'region' => mb_substr((string) ($input['region'] ?? ''), 0, 120) ?: null,
                        'city' => mb_substr((string) ($input['city'] ?? ''), 0, 120) ?: null, 'address_text' => mb_substr((string) ($input['address_text'] ?? ''), 0, 500) ?: null,
                        'business_category' => mb_substr((string) ($input['business_category'] ?? data_get($input, 'structured_data.industry') ?? ''), 0, 120) ?: null,
                        'phone_values' => json_encode($phones), 'email_values' => json_encode($emails), 'structured_data' => json_encode($structured),
                        'outbound_business_links' => json_encode($links),
                        'document_type' => in_array(($input['document_type'] ?? 'business'), ['business', 'directory'], true) ? ($input['document_type'] ?? 'business') : 'business',
                        'source' => mb_substr((string) ($input['source'] ?? $source->name()), 0, 64),
                        'source_reference' => mb_substr((string) ($input['source_reference'] ?? ''), 0, 1000) ?: null,
                        'source_timestamp' => $this->timestamp($input['source_timestamp'] ?? null), 'indexed_at' => $now,
                    ];
                    $searchText = implode(' ', array_filter([$safe['organization_name'], $safe['page_title'], $safe['description'], $safe['visible_text_excerpt'], $safe['country'], $safe['city'], $safe['address_text'], $safe['business_category'], ...$phones, ...$emails, (string) data_get($structured, 'industry')]));
                    $hashInput = $safe;
                    unset($hashInput['source'], $hashInput['source_reference'], $hashInput['source_timestamp'], $hashInput['indexed_at']);
                    $hashStructured = json_decode((string) $hashInput['structured_data'], true) ?: [];
                    if (is_array($hashStructured['identity_pages'] ?? null)) foreach ($hashStructured['identity_pages'] as &$identityPage) unset($identityPage['retrieved_at']);
                    unset($identityPage);
                    $hashInput['structured_data'] = json_encode($hashStructured);
                    $hash = hash('sha256', json_encode([$hashInput, $searchText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $existing = DB::table('web_index_documents')->where('normalized_domain', $safe['normalized_domain'])->where('canonical_url', $safe['canonical_url'])->first();
                    if ($existing) $duplicates++;
                    $domainExists = DB::table('web_index_documents')->where('normalized_domain', $safe['normalized_domain'])->exists();
                    if (! $existing && $domainExists) $duplicates++;
                    if (! $existing && ! $domainExists && $uniqueDomainsAdded >= min(500, max(1, (int) ($options['max_unique_domains'] ?? config('website_resolution.index_ingestion_max_unique_domains', 500))))) {
                        $processed--; $partial = true; break;
                    }
                    $storedBytes = strlen($searchText) + strlen((string) $safe['structured_data']) + strlen((string) $safe['phone_values']) + strlen((string) $safe['email_values']);
                    $hasContact = $phones !== [] || $emails !== [];
                    $hasStructured = is_array($structured) && is_array($structured['entities'] ?? null) && $structured['entities'] !== [];
                    $completeness = 30 + (($safe['city'] ?? null) ? 10 : 0) + (($safe['country'] ?? null) ? 10 : 0)
                        + ($hasContact ? 20 : 0) + ($hasStructured ? 10 : 0) + (($safe['description'] || $safe['visible_text_excerpt']) ? 10 : 0)
                        + (($safe['address_text'] ?? null) ? 5 : 0) + (($safe['page_title'] ?? null) ? 5 : 0);
                    $values = [...$safe, 'content_hash' => $hash, 'normalized_name' => $this->normalizer->name($safe['organization_name']),
                        'normalized_city' => $this->normalizer->text($safe['city']), 'normalized_country' => $this->normalizer->country($safe['country']),
                        'normalized_address' => $this->normalizer->text($safe['address_text']), 'normalized_phone' => $this->normalizer->phone($phones[0] ?? ''),
                        'search_text' => mb_substr($searchText, 0, 12000), 'availability' => 'available', 'last_seen_at' => $now,
                        'last_fetched_at' => $now, 'next_refresh_at' => $now->copy()->addSeconds(max(3600, (int) config('website_resolution.refresh_success_seconds', 604800))),
                        'stored_bytes' => $storedBytes, 'has_public_contact' => $hasContact, 'has_structured_data' => $hasStructured,
                        'identity_completeness' => min(100, $completeness), 'updated_at' => $now];
                    if ($existing && $source->name() === 'verified_discovery') {
                        // Discovery metadata is useful provenance, but it is not a fresh page fetch.
                        // Do not replace richer website evidence with a smaller candidate snapshot.
                        DB::table('web_index_documents')->where('id', $existing->id)->update(['last_seen_at' => $now, 'updated_at' => $now]);
                        $unchanged++;
                        $documentId = $existing->id;
                    } elseif ($existing && hash_equals((string) $existing->content_hash, $hash)) {
                        DB::table('web_index_documents')->where('id', $existing->id)->update(['first_seen_at' => $existing->first_seen_at ?? $existing->indexed_at,
                            'last_seen_at' => $now, 'last_fetched_at' => $now, 'next_refresh_at' => $values['next_refresh_at'], 'availability' => 'available',
                            'structured_data' => $safe['structured_data'], 'stored_bytes' => $storedBytes, 'updated_at' => $now]);
                        $unchanged++;
                        $documentId = $existing->id;
                    } elseif ($existing) {
                        $contentChangedAt = $now;
                        DB::table('web_index_documents')->where('id', $existing->id)->update([...$values, 'first_seen_at' => $existing->first_seen_at ?? $existing->indexed_at,
                            'content_changed_at' => $contentChangedAt, 'created_at' => $existing->created_at]);
                        $storedBytesDelta += $storedBytes - (int) $existing->stored_bytes;
                        $updated++;
                        $documentId = $existing->id;
                    } else {
                        $documentId = (string) Str::uuid();
                        DB::table('web_index_documents')->insert(['id' => $documentId, ...$values, 'first_seen_at' => $now, 'content_changed_at' => $now, 'created_at' => $now]);
                        $storedBytesDelta += $storedBytes; $inserted++;
                        if (! DB::table('web_index_documents')->where('normalized_domain', $safe['normalized_domain'])->where('id', '!=', $documentId)->exists()) $uniqueDomainsAdded++;
                    }
                    $this->recordProvenance($documentId, $input, $source, $resumeRunId, $now);
                } catch (Throwable) { $failed++; }

                $cursor = $inputCursor ?: $cursor;
                $this->checkpoint($resumeRunId, $processed, $inserted, $updated, $unchanged, $failed, $duplicates, $uniqueDomainsAdded, $bytesProcessed, $storedBytesDelta, $cursor);
                if (microtime(true) - $started >= max(10, (int) ($options['max_runtime_seconds'] ?? config('website_resolution.index_ingestion_max_duration_seconds', 300)))
                    || $failed >= max(1, (int) ($options['max_failures'] ?? 50))) { $partial = true; break; }
            }
        $sourceMetrics = array_merge($source->metrics(), $sourceMetrics);
        } catch (Throwable $error) {
            $sourceMetrics = array_merge($source->metrics(), $sourceMetrics);
            $this->finish($resumeRunId, 'failed', $sourceMetrics, $processed, $inserted, $updated, $unchanged, $failed + 1, $duplicates, $uniqueDomainsAdded, $bytesProcessed, $storedBytesDelta);
            throw new \RuntimeException('The configured local index source failed.');
        }

        if (($sourceMetrics['fetch_budget_exhausted'] ?? 0) > 0 || ($sourceMetrics['byte_budget_exhausted'] ?? 0) > 0
            || ($sourceMetrics['time_budget_exhausted'] ?? 0) > 0 || ($sourceMetrics['entity_budget_exhausted'] ?? 0) > 0
            || ($sourceMetrics['domain_budget_exhausted'] ?? 0) > 0 || ($sourceMetrics['record_budget_exhausted'] ?? 0) > 0) $partial = true;
        $sourceMetrics = $this->accumulateMetrics($priorMetrics, $sourceMetrics);
        $metrics = [...$sourceMetrics, 'source_failures' => $this->sourceFailures($sourceMetrics), 'documents_per_second' => round($processed / max(0.001, microtime(true) - $started), 2)];
        $partial = $partial || $failed > 0 || $metrics['source_failures'] > 0;
        $this->finish($resumeRunId, $partial ? 'partially_completed' : 'completed', $metrics, $processed, $inserted, $updated, $unchanged, $failed, $duplicates, $uniqueDomainsAdded, $bytesProcessed, $storedBytesDelta);
        return ['run_id' => $resumeRunId, 'source' => $source->name(), 'status' => $partial ? 'partially_completed' : 'completed',
            'processed' => $processed, 'inserted' => $inserted, 'updated' => $updated, 'unchanged' => $unchanged, 'failed' => $failed,
            'duplicates' => $duplicates, 'unique_domains_added' => $uniqueDomainsAdded, 'bytes_processed' => $bytesProcessed,
            'stored_bytes_delta' => $storedBytesDelta, 'source_failures' => $metrics['source_failures'], 'source_metrics' => $metrics];
    }

    /** Requeue an existing resumable run through the dedicated queue after merging explicit safe budgets. */
    public function prepareQueuedResume(WebIndexIngestionSourceInterface $source, string $runId, int $limit, array $options = []): string
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Index ingestion limit must be between 1 and 500.');
        return DB::transaction(function () use ($source, $runId, $limit, $options): string {
            $run = DB::table('web_index_ingestion_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->source !== $source->name() || ! in_array($run->status, ['pending', 'running', 'paused', 'partially_completed', 'failed'], true)) {
                throw new InvalidArgumentException('The ingestion run cannot be resumed.');
            }
            $storedOptions = is_array($run->options) ? $run->options : (json_decode((string) $run->options, true) ?: []);
            DB::table('web_index_ingestion_runs')->where('id', $runId)->update([
                'requested_limit' => max((int) $run->requested_limit, $limit),
                'options' => json_encode(array_merge($storedOptions, $options)),
                'status' => 'pending', 'resumed_at' => now(), 'finished_at' => null, 'updated_at' => now(),
            ]);
            return $runId;
        });
    }

    private function accumulateMetrics(array $prior, array $current): array
    {
        foreach ($current as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $current[$key] = $key === 'documents_per_second' ? $value : $value + (is_numeric($prior[$key] ?? null) ? $prior[$key] : 0);
            }
        }
        return array_merge($prior, $current);
    }

    private function finish(string $runId, string $status, array $metrics, int $processed, int $inserted, int $updated, int $unchanged, int $failed, int $duplicates, int $uniqueDomainsAdded, int $bytesProcessed, int $storedBytesDelta): void
    {
        DB::table('web_index_ingestion_runs')->where('id', $runId)->update(['status' => $status, 'metrics' => json_encode($metrics), 'processed' => $processed,
            'inserted' => $inserted, 'updated' => $updated, 'unchanged' => $unchanged, 'failed' => $failed, 'duplicates' => $duplicates,
            'source_failures' => $this->sourceFailures($metrics),
            'unique_domains_added' => $uniqueDomainsAdded, 'bytes_processed' => $bytesProcessed, 'stored_bytes_delta' => $storedBytesDelta,
            'failure_summary' => $failed ? 'Some bounded document operations failed; inspect classified metrics.' : null, 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function checkpoint(string $runId, int $processed, int $inserted, int $updated, int $unchanged, int $failed, int $duplicates, int $uniqueDomainsAdded, int $bytesProcessed, int $storedBytesDelta, ?array $cursor): void
    {
        DB::table('web_index_ingestion_runs')->where('id', $runId)->update(['processed' => $processed, 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $unchanged, 'failed' => $failed, 'duplicates' => $duplicates, 'unique_domains_added' => $uniqueDomainsAdded,
            'bytes_processed' => $bytesProcessed, 'stored_bytes_delta' => $storedBytesDelta, 'cursor' => json_encode($cursor),
            'checkpointed_at' => now(), 'updated_at' => now()]);
    }

    private function refreshDelay(string $failureCode): int
    {
        return match ($failureCode) {
            'ROBOTS_DENIED', 'ROBOTS_UNAVAILABLE' => max(86400, (int) config('website_resolution.refresh_robots_denied_seconds', 2592000)),
            'UNSAFE_URL', 'UNSAFE_REDIRECT', 'DIRECTORY_EVIDENCE' => max(86400, (int) config('website_resolution.refresh_unsafe_url_seconds', 31536000)),
            default => max(300, (int) config('website_resolution.refresh_transient_failure_seconds', 21600)),
        };
    }

    private function recordProvenance(string $documentId, array $input, WebIndexIngestionSourceInterface $source, string $runId, $now): void
    {
        $sourceName = mb_substr((string) ($input['source'] ?? $source->name()), 0, 64);
        $sourceReference = mb_substr((string) ($input['source_reference'] ?? 'unknown'), 0, 1000);
        $sourceQuery = is_array($input['source_query'] ?? null) ? $input['source_query'] : [];
        if (strlen((string) json_encode($sourceQuery)) > 8000) $sourceQuery = ['truncated' => true];
        $existing = DB::table('web_index_document_sources')->where('document_id', $documentId)->where('source', $sourceName)->where('source_reference', $sourceReference)->first();
        if ($existing) DB::table('web_index_document_sources')->where('id', $existing->id)->update(['source_query' => json_encode($sourceQuery),
            'evidence_type' => mb_substr((string) ($input['evidence_type'] ?? 'business_website'), 0, 32),
            'source_timestamp' => $this->timestamp($input['source_timestamp'] ?? null), 'last_observed_at' => $now, 'last_ingestion_run_id' => $runId, 'updated_at' => $now]);
        else DB::table('web_index_document_sources')->insert(['id' => (string) Str::uuid(), 'document_id' => $documentId, 'source' => $sourceName,
            'source_reference' => $sourceReference, 'source_query' => json_encode($sourceQuery),
            'evidence_type' => mb_substr((string) ($input['evidence_type'] ?? 'business_website'), 0, 32),
            'source_timestamp' => $this->timestamp($input['source_timestamp'] ?? null), 'first_observed_at' => $now, 'last_observed_at' => $now,
            'last_ingestion_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function normalizeLinks(array $links, string $domain): array
    {
        $safe = [];
        foreach (array_slice($links, 0, 20) as $link) {
            if (! is_array($link) || ! is_string($link['url'] ?? null)) continue;
            try { $target = $this->domains->normalize($link['url']); } catch (Throwable) { continue; }
            if ($target['normalized_domain'] === $domain) continue;
            $safe[] = ['url' => $target['normalized_url'], 'organization_name' => mb_substr((string) ($link['organization_name'] ?? ''), 0, 255),
                'city' => mb_substr((string) ($link['city'] ?? ''), 0, 120), 'country' => mb_substr((string) ($link['country'] ?? ''), 0, 100), 'phone' => mb_substr((string) ($link['phone'] ?? ''), 0, 40)];
        }
        return $safe;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        try { return \Illuminate\Support\Carbon::parse($value)->toIso8601String(); } catch (Throwable) { return null; }
    }

    private function sourceFailures(array $metrics): int
    {
        $keys = ['osm_query_failures', 'dns_resolution_failures', 'ssrf_policy_rejections', 'url_normalization_rejections', 'robots_dns_failures', 'robots_tls_failures',
            'robots_timeouts', 'robots_redirect_rejections', 'robots_transport_failures', 'robots_http_unavailable', 'robots_denied', 'candidate_dns_failures',
            'candidate_tls_failures', 'candidate_timeouts', 'candidate_redirect_rejections', 'candidate_transport_failures', 'candidate_http_failures', 'candidate_non_html', 'candidate_oversize'];
        $classified = array_filter($metrics, fn ($value, $key) => is_string($key) && str_starts_with($key, 'failure_'), ARRAY_FILTER_USE_BOTH);
        $sourceReported = max(0, (int) ($metrics['source_failures'] ?? 0));
        $detailed = array_sum(array_intersect_key($metrics, array_flip($keys)));
        return max($sourceReported, $detailed) + array_sum($classified);
    }
}
