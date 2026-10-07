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

    public function ingest(WebIndexIngestionSourceInterface $source, int $limit): array
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Index ingestion limit must be between 1 and 500.');
        $runId = (string) Str::uuid();
        DB::table('web_index_ingestion_runs')->insert(['id' => $runId, 'source' => $source->name(), 'requested_limit' => $limit,
            'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $processed = $inserted = $updated = $failed = 0;
        try {
            foreach ($source->documents($limit) as $input) {
                $processed++;
                try {
                $url = $this->domains->normalize((string) ($input['canonical_url'] ?? ''));
                $name = trim((string) ($input['organization_name'] ?? ''));
                if ($name === '') { $failed++; continue; }
                $phones = array_values(array_filter((array) ($input['phone_values'] ?? []), fn ($value) => is_string($value) && $value !== ''));
                $emails = array_values(array_filter((array) ($input['email_values'] ?? []), fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)));
                $links = [];
                foreach (array_slice((array) ($input['outbound_business_links'] ?? []), 0, 20) as $link) {
                    if (! is_array($link) || ! is_string($link['url'] ?? null)) continue;
                    try { $target = $this->domains->normalize($link['url']); } catch (Throwable) { continue; }
                    if ($target['normalized_domain'] === $url['normalized_domain']) continue;
                    $links[] = ['url' => $target['normalized_url'], 'organization_name' => mb_substr((string) ($link['organization_name'] ?? ''), 0, 255),
                        'city' => mb_substr((string) ($link['city'] ?? ''), 0, 120), 'country' => mb_substr((string) ($link['country'] ?? ''), 0, 100),
                        'phone' => mb_substr((string) ($link['phone'] ?? ''), 0, 40)];
                }
                $structured = $input['structured_data'] ?? [];
                if (strlen((string) json_encode($structured)) > 32000) $structured = [];
                $safe = [
                    'canonical_url' => $url['normalized_url'], 'normalized_domain' => $url['normalized_domain'],
                    'page_title' => mb_substr((string) ($input['page_title'] ?? ''), 0, 500) ?: null,
                    'organization_name' => mb_substr($name, 0, 255), 'description' => mb_substr((string) ($input['description'] ?? ''), 0, 2000) ?: null,
                    'visible_text_excerpt' => mb_substr((string) ($input['visible_text_excerpt'] ?? ''), 0, 4000) ?: null,
                    'country' => mb_substr((string) ($input['country'] ?? ''), 0, 100) ?: null, 'region' => mb_substr((string) ($input['region'] ?? ''), 0, 120) ?: null,
                    'city' => mb_substr((string) ($input['city'] ?? ''), 0, 120) ?: null, 'address_text' => mb_substr((string) ($input['address_text'] ?? ''), 0, 500) ?: null,
                    'phone_values' => json_encode($phones), 'email_values' => json_encode($emails),
                    'structured_data' => json_encode($structured), 'outbound_business_links' => json_encode($links),
                    'document_type' => in_array(($input['document_type'] ?? 'business'), ['business', 'directory'], true) ? $input['document_type'] ?? 'business' : 'business',
                    'source' => mb_substr((string) ($input['source'] ?? $source->name()), 0, 64), 'source_reference' => mb_substr((string) ($input['source_reference'] ?? ''), 0, 1000) ?: null,
                    'source_timestamp' => $input['source_timestamp'] ?? null, 'indexed_at' => now(),
                ];
                $searchText = implode(' ', array_filter([$safe['organization_name'], $safe['page_title'], $safe['description'], $safe['visible_text_excerpt'], $safe['country'], $safe['city'], $safe['address_text'], ...$phones, ...$emails, (string) data_get($input, 'structured_data.industry')]));
                $hashInput = $safe;
                unset($hashInput['indexed_at']);
                $hash = hash('sha256', json_encode([$hashInput, $searchText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $existing = DB::table('web_index_documents')->where('normalized_domain', $safe['normalized_domain'])->where('canonical_url', $safe['canonical_url'])->first(['id', 'content_hash']);
                if ($existing && hash_equals((string) $existing->content_hash, $hash)) continue;
                $values = [...$safe,
                    'content_hash' => $hash, 'normalized_name' => $this->normalizer->name($safe['organization_name']),
                    'normalized_city' => $this->normalizer->text($safe['city']), 'normalized_country' => $this->normalizer->country($safe['country']),
                    'normalized_address' => $this->normalizer->text($safe['address_text']), 'normalized_phone' => $this->normalizer->phone($phones[0] ?? ''),
                    'search_text' => mb_substr($searchText, 0, 12000), 'updated_at' => now(),
                ];
                if ($existing) { DB::table('web_index_documents')->where('id', $existing->id)->update($values); $updated++; }
                else { DB::table('web_index_documents')->insert(['id' => (string) Str::uuid(), ...$values, 'created_at' => now()]); $inserted++; }
                } catch (Throwable) { $failed++; }
            }
        } catch (Throwable) {
            $failed++;
            DB::table('web_index_ingestion_runs')->where('id', $runId)->update(['status' => 'failed', 'failure_summary' => 'The ingestion source failed; source details were omitted.', 'finished_at' => now(), 'updated_at' => now(),
                'processed' => $processed, 'inserted' => $inserted, 'updated' => $updated, 'unchanged' => $processed - $inserted - $updated - $failed, 'failed' => $failed]);
            throw new \RuntimeException('The configured local index source failed.');
        }
        $sourceMetrics = $source->metrics();
        $sourceFailureKeys = ['osm_query_failures', 'dns_resolution_failures', 'ssrf_policy_rejections', 'url_normalization_rejections',
            'robots_dns_failures', 'robots_tls_failures', 'robots_timeouts', 'robots_redirect_rejections', 'robots_transport_failures', 'robots_http_unavailable',
            'candidate_dns_failures', 'candidate_tls_failures', 'candidate_timeouts', 'candidate_redirect_rejections', 'candidate_transport_failures',
            'candidate_http_failures', 'candidate_non_html', 'candidate_oversize', 'time_budget_exhausted'];
        $sourceFailures = array_sum(array_intersect_key($sourceMetrics, array_flip($sourceFailureKeys)));
        $result = ['source' => $source->name(), 'processed' => $processed, 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $processed - $inserted - $updated - $failed, 'failed' => $failed, 'source_failures' => $sourceFailures, 'source_metrics' => $sourceMetrics];
        DB::table('web_index_ingestion_runs')->where('id', $runId)->update([...collect($result)->except(['source', 'source_metrics'])->all(), 'metrics' => json_encode($result['source_metrics']), 'status' => $failed ? 'completed_with_errors' : 'completed', 'finished_at' => now(), 'updated_at' => now()]);
        return $result;
    }
}
