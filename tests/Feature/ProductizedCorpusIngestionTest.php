<?php

namespace Tests\Feature;

use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceInterface;
use App\WebsiteResolution\LocalWebIndexSearchService;
use App\Crawling\PublicAddressResolverInterface;
use App\WebsiteResolution\WebIndexRefreshSource;
use App\WebsiteResolution\OpenStreetMapWebsiteIndexIngestionSource;
use App\WebsiteResolution\CommonCrawlOfflineArtifactSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductizedCorpusIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_common_crawl_artifact_uses_normal_dedup_provenance_and_never_outreaches(): void
    {
        $html = '<html><head><title>Fixture Clinic</title><script type="application/ld+json">{"@type":"LocalBusiness","name":"Fixture Clinic","address":{"@type":"PostalAddress","addressLocality":"Kochi","addressCountry":"India"},"telephone":"+91 98765 43210"}</script></head><body>Our clinic provides healthcare services. Contact our business today.</body></html>';
        $payload = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n".$html;
        $warc = "WARC/1.0\r\nWARC-Type: response\r\nWARC-Target-URI: https://fixture-clinic.test/\r\nWARC-Date: 2026-09-01T00:00:00Z\r\nContent-Length: ".strlen($payload)."\r\n\r\n{$payload}\r\n\r\n";
        $path = tempnam(sys_get_temp_dir(), 'cc-ingest-').'.warc.gz';
        file_put_contents($path, gzencode($warc));
        try {
            $source = app(CommonCrawlOfflineArtifactSource::class);
            $service = app(LocalWebIndexIngestionService::class);
            $options = ['artifact_path' => $path, 'crawl_id' => 'CC-MAIN-2026-39', 'max_bytes' => 100_000,
                'max_artifact_bytes' => 100_000, 'max_records' => 10, 'max_document_bytes' => 20_000, 'max_runtime_seconds' => 30, 'max_domains' => 10];
            $first = $service->ingest($source, 10, $options);
            $second = $service->ingest(app(CommonCrawlOfflineArtifactSource::class), 10, $options);
            $document = DB::table('web_index_documents')->where('normalized_domain', 'fixture-clinic.test')->first();
            $provenance = DB::table('web_index_document_sources')->where('document_id', $document->id)->first();
            self::assertSame(1, $first['unique_domains_added']);
            self::assertSame(1, $second['unchanged']);
            self::assertSame('Kochi', $document->city);
            self::assertSame('common_crawl_offline_artifact', $provenance->source);
            self::assertSame('CC-MAIN-2026-39', json_decode((string) $provenance->source_query, true)['crawl_id']);
            self::assertSame(0, DB::table('campaign_recipients')->count());
            self::assertSame(0, DB::table('outbound_messages')->count());
            self::assertSame(0, DB::table('workflow_approvals')->where('action', 'SEND_OUTREACH')->count());
            self::assertSame(0, DB::table('meeting_bookings')->count());
            self::assertSame(0, DB::table('proposals')->count());
        } finally { @unlink($path); }
    }

    public function test_offline_artifact_checkpoint_resumes_after_skipped_record_without_duplicate_documents(): void
    {
        $record = function (string $url, string $payload, string $type = 'text/html'): string {
            $http = "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\n\r\n".$payload;
            return "WARC/1.0\r\nWARC-Type: response\r\nWARC-Target-URI: {$url}\r\nWARC-Date: 2026-09-01T00:00:00Z\r\nContent-Length: ".strlen($http)."\r\n\r\n{$http}\r\n\r\n";
        };
        $business = '<html><head><title>Resume Dental</title><script type="application/ld+json">{"@type":"LocalBusiness","name":"Resume Dental","address":{"@type":"PostalAddress","addressLocality":"Kochi","addressCountry":"India"}}</script></head><body>Dental clinic providing medical services and care.</body></html>';
        $path = tempnam(sys_get_temp_dir(), 'cc-resume-').'.warc.gz';
        file_put_contents($path, gzencode($record('https://news.example.test/story', 'plain text', 'text/plain').$record('https://resume-dental.test/', $business)));
        try {
            $source = app(CommonCrawlOfflineArtifactSource::class);
            $service = app(LocalWebIndexIngestionService::class);
            $options = ['artifact_path' => $path, 'crawl_id' => 'CC-MAIN-2026-39', 'max_bytes' => 100_000,
                'max_artifact_bytes' => 100_000, 'max_records' => 1, 'max_document_bytes' => 20_000, 'max_runtime_seconds' => 30, 'max_domains' => 10];
            $first = $service->ingest($source, 10, $options);
            self::assertSame('partially_completed', $first['status']);
            self::assertSame(['artifact' => hash_file('sha256', $path), 'record' => 1], json_decode((string) DB::table('web_index_ingestion_runs')->where('id', $first['run_id'])->value('cursor'), true));
            $options['max_records'] = 10;
            $resumed = $service->ingest(app(CommonCrawlOfflineArtifactSource::class), 10, $options, $first['run_id']);
            self::assertSame('completed', $resumed['status']);
            self::assertSame(1, DB::table('web_index_documents')->where('normalized_domain', 'resume-dental.test')->count());
            self::assertSame(1, DB::table('web_index_document_sources')->where('source', 'common_crawl_offline_artifact')->count());
        } finally { @unlink($path); }
    }

    public function test_public_document_is_shared_and_distinct_source_provenance_is_preserved(): void
    {
        $service = app(LocalWebIndexIngestionService::class);
        $first = $this->source([['canonical_url' => 'https://shared-acme.test/', 'organization_name' => 'Acme Industrial', 'city' => 'Chennai', 'country' => 'India',
            'visible_text_excerpt' => 'Industrial supply company', 'source' => 'osm_public_websites', 'source_reference' => 'way:1:website',
            'source_query' => ['location' => 'Chennai', 'category' => 'manufacturing'], 'evidence_type' => 'feature_website']]);
        $second = $this->source([['canonical_url' => 'https://shared-acme.test/', 'organization_name' => 'Acme Industrial', 'city' => 'Chennai', 'country' => 'India',
            'visible_text_excerpt' => 'Industrial supply company', 'source' => 'wikidata_linked_websites', 'source_reference' => 'Q123:P856',
            'source_query' => ['entity_id' => 'Q123', 'property' => 'P856'], 'evidence_type' => 'linked_entity']]);

        $added = $service->ingest($first, 10);
        $enriched = $service->ingest($second, 10);

        self::assertSame(1, $added['unique_domains_added']);
        self::assertSame(1, $enriched['unchanged']);
        self::assertSame(1, DB::table('web_index_documents')->where('normalized_domain', 'shared-acme.test')->count());
        self::assertSame(2, DB::table('web_index_document_sources')->whereIn('source', ['osm_public_websites', 'wikidata_linked_websites'])->count());
        self::assertSame(0, DB::table('campaign_recipients')->count());
        self::assertSame(0, DB::table('outbound_messages')->count());
        self::assertSame(0, DB::table('workflow_approvals')->where('action', 'SEND_OUTREACH')->count());
        self::assertFalse(Schema::hasColumn('web_index_documents', 'tenant_id'));
    }

    public function test_verified_discovery_provenance_does_not_replace_richer_fetched_page_evidence(): void
    {
        $service = app(LocalWebIndexIngestionService::class);
        $url = 'https://evidence-acme.test/';
        $service->ingest($this->source([['canonical_url' => $url, 'organization_name' => 'Evidence Acme', 'page_title' => 'Evidence Acme Official',
            'description' => 'A public industrial company website.', 'phone_values' => ['+1 555 0100'], 'email_values' => ['info@evidence-acme.test'],
            'structured_data' => ['source_url' => $url, 'entities' => [['name' => 'Evidence Acme']]], 'source' => 'osm_public_websites', 'source_reference' => 'node:1:website']]), 1);
        $before = DB::table('web_index_documents')->where('normalized_domain', 'evidence-acme.test')->first();

        $service->ingest($this->source([['canonical_url' => $url, 'organization_name' => 'Acme', 'page_title' => 'Acme',
            'country' => 'India', 'source' => 'verified_open_discovery', 'source_reference' => 'candidate:1']], 'verified_discovery'), 1);

        $after = DB::table('web_index_documents')->where('normalized_domain', 'evidence-acme.test')->first();
        self::assertSame($before->content_hash, $after->content_hash);
        self::assertSame('Evidence Acme Official', $after->page_title);
        self::assertSame(['+1 555 0100'], json_decode((string) $after->phone_values, true));
        self::assertSame(['info@evidence-acme.test'], json_decode((string) $after->email_values, true));
        self::assertTrue((bool) $after->has_structured_data);
        self::assertSame(2, DB::table('web_index_document_sources')->where('document_id', $after->id)->count());
    }

    public function test_coverage_improves_from_added_public_evidence_without_changing_resolution_thresholds(): void
    {
        config(['website_resolution.medium_confidence_threshold' => 50, 'website_resolution.high_confidence_threshold' => 85]);
        $search = app(LocalWebIndexSearchService::class);
        $identity = ['business_name' => 'Nile Medical Supply', 'city' => 'Kochi', 'country' => 'India', 'category' => 'healthcare'];
        self::assertSame([], $search->search($identity));

        app(LocalWebIndexIngestionService::class)->ingest($this->source([['canonical_url' => 'https://nilemed.example/', 'organization_name' => 'Nile Medical Supply',
            'city' => 'Kochi', 'country' => 'India', 'description' => 'Medical supply and healthcare equipment', 'source' => 'fixture_public_source', 'source_reference' => 'fixture:nile']] ), 10);

        $results = $search->search($identity);
        self::assertCount(1, $results);
        self::assertSame('nilemed.example', $results[0]['document']->normalized_domain);
        self::assertSame(50, config('website_resolution.medium_confidence_threshold'));
        self::assertSame(85, config('website_resolution.high_confidence_threshold'));
    }

    public function test_osm_seed_inspection_reports_tagged_url_yield_without_fetching_or_index_writes(): void
    {
        config(['discovery.osm_min_delay_ms' => 0]);
        Http::preventStrayRequests();
        Http::fake(['https://overpass-api.de/api/interpreter' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 13, 'lon' => 80, 'tags' => ['name' => 'Acme', 'website' => 'https://www.acme.example.com', 'contact:website' => 'https://acme.example.com/']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 13, 'lon' => 80], 'tags' => ['name' => 'Retail Shop', 'website' => 'https://facebook.com/acme', 'brand:website' => 'https://brand.example.com', 'operator:website' => 'ftp://unsafe.example.com']],
            ['type' => 'node', 'id' => 3, 'lat' => 13, 'lon' => 80, 'tags' => ['name' => 'Other', 'contact:website' => 'https://other.example.net']],
        ]])]);
        $source = app(OpenStreetMapWebsiteIndexIngestionSource::class);

        $metrics = $source->inspectSeeds('Chennai', 'India', 'retail', 10);

        self::assertSame(3, $metrics['business_records']);
        self::assertSame(2, $metrics['website_refs']);
        self::assertSame(2, $metrics['contact_website_refs']);
        self::assertSame(1, $metrics['brand_website_refs']);
        self::assertSame(1, $metrics['operator_website_refs']);
        self::assertSame(3, $metrics['usable_normalized_domains']);
        self::assertSame(1, $metrics['duplicate_urls']);
        self::assertSame(1, $metrics['directory_or_social']);
        self::assertSame(1, $metrics['url_normalization_rejections']);
        self::assertSame(0, DB::table('web_index_documents')->count());
        self::assertSame(0, DB::table('web_index_ingestion_runs')->count());
    }

    public function test_domain_budget_yields_a_resumable_partial_run_and_checkpoints(): void
    {
        $rows = [
            ['canonical_url' => 'https://resume-one.example/', 'organization_name' => 'Resume One', 'source_reference' => 'seed:1', '_cursor' => ['position' => 1]],
            ['canonical_url' => 'https://resume-two.example/', 'organization_name' => 'Resume Two', 'source_reference' => 'seed:2', '_cursor' => ['position' => 2]],
        ];
        $source = $this->source($rows, 'resumable_fixture');
        $service = app(LocalWebIndexIngestionService::class);
        $first = $service->ingest($source, 2, ['max_unique_domains' => 1]);
        self::assertSame('partially_completed', $first['status']);
        $run = DB::table('web_index_ingestion_runs')->where('id', $first['run_id'])->first();
        self::assertSame(1, $run->processed);
        self::assertSame(['position' => 1], json_decode((string) $run->cursor, true));
        self::assertNotNull($run->checkpointed_at);

        $resumed = $service->ingest($source, 2, ['max_unique_domains' => 2], $first['run_id']);
        self::assertSame('completed', $resumed['status'], json_encode($resumed));
        self::assertSame(2, DB::table('web_index_documents')->count());
        self::assertSame(2, DB::table('web_index_ingestion_runs')->where('id', $first['run_id'])->value('processed'));
    }

    public function test_osm_resume_cursor_continues_after_the_last_persisted_seed(): void
    {
        config(['discovery.osm_min_delay_ms' => 0, 'website_resolution.max_identity_pages_per_domain' => 0]);
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === 'https://overpass-api.de/api/interpreter') return Http::response(['elements' => [
                ['type' => 'node', 'id' => 101, 'lat' => 13, 'lon' => 80, 'tags' => ['name' => 'Resume One', 'shop' => 'clothes', 'website' => 'https://osm-resume-one.test/']],
                ['type' => 'node', 'id' => 102, 'lat' => 13, 'lon' => 80, 'tags' => ['name' => 'Resume Two', 'shop' => 'clothes', 'website' => 'https://osm-resume-two.test/']],
                ['type' => 'node', 'id' => 103, 'lat' => 13, 'lon' => 80, 'tags' => ['name' => 'Resume Three', 'shop' => 'clothes', 'website' => 'https://osm-resume-three.test/']],
            ]]);
            if (str_ends_with($url, '/robots.txt')) {
                RateLimiter::clear('crawl-host:'.hash('sha256', (string) parse_url($url, PHP_URL_HOST)));
                return Http::response('', 404);
            }
            RateLimiter::clear('crawl-host:'.hash('sha256', (string) parse_url($url, PHP_URL_HOST)));
            return Http::response('<title>'.parse_url($url, PHP_URL_HOST).'</title><p>Public retail business in Chennai, India.</p>', 200, ['Content-Type' => 'text/html']);
        });

        $service = app(LocalWebIndexIngestionService::class);
        $source = app(OpenStreetMapWebsiteIndexIngestionSource::class);
        $first = $service->ingest($source, 3, ['location' => 'Chennai', 'country' => 'India', 'categories' => ['retail'],
            'max_source_records' => 3, 'max_fetches' => 1, 'max_runtime_seconds' => 60]);
        self::assertSame('partially_completed', $first['status']);
        $savedCursor = json_decode((string) DB::table('web_index_ingestion_runs')->where('id', $first['run_id'])->value('cursor'), true);
        self::assertSame('node:101:website', $savedCursor['source_reference']);

        $resumed = $service->ingest(app(OpenStreetMapWebsiteIndexIngestionSource::class), 3, ['location' => 'Chennai', 'country' => 'India', 'categories' => ['retail'],
            'max_source_records' => 3, 'max_fetches' => 3, 'max_runtime_seconds' => 60], $first['run_id']);

        self::assertSame('completed', $resumed['status'], json_encode($resumed));
        self::assertSame(3, DB::table('web_index_ingestion_runs')->where('id', $first['run_id'])->value('processed'));
        self::assertSame(3, DB::table('web_index_documents')->count());
        self::assertSame(3, DB::table('web_index_document_sources')->count());
    }

    public function test_source_failures_leave_run_partially_completed_for_operator_review(): void
    {
        $result = app(LocalWebIndexIngestionService::class)->ingest($this->source([], 'flaky_fixture', ['source_failures' => 2]), 5);

        self::assertSame('partially_completed', $result['status']);
        self::assertSame(2, $result['source_failures']);
    }

    public function test_aggregate_and_classified_source_failure_metrics_are_not_double_counted(): void
    {
        $result = app(LocalWebIndexIngestionService::class)->ingest($this->source([], 'classified_fixture', [
            'source_failures' => 2, 'candidate_dns_failures' => 1, 'ssrf_policy_rejections' => 1,
        ]), 5);

        self::assertSame(2, $result['source_failures']);
        self::assertSame(2, (int) DB::table('web_index_ingestion_runs')->where('id', $result['run_id'])->value('source_failures'));
    }

    public function test_only_explicit_schema_entities_count_as_structured_identity_evidence(): void
    {
        $service = app(LocalWebIndexIngestionService::class);
        $service->ingest($this->source([
            ['canonical_url' => 'https://business-meta.example/', 'organization_name' => 'Business Meta', 'structured_data' => ['industry' => 'retail']],
            ['canonical_url' => 'https://business-schema.example/', 'organization_name' => 'Business Schema', 'structured_data' => ['source_url' => 'https://business-schema.example/', 'entities' => [['name' => 'Business Schema']]]],
        ]), 5);

        self::assertFalse((bool) DB::table('web_index_documents')->where('normalized_domain', 'business-meta.example')->value('has_structured_data'));
        self::assertTrue((bool) DB::table('web_index_documents')->where('normalized_domain', 'business-schema.example')->value('has_structured_data'));
    }

    public function test_refresh_marks_transient_failures_unavailable_and_schedules_retry(): void
    {
        app(LocalWebIndexIngestionService::class)->ingest($this->source([['canonical_url' => 'https://retry.example/', 'organization_name' => 'Retry Company']]), 1);
        DB::table('web_index_documents')->where('normalized_domain', 'retry.example')->update(['next_refresh_at' => now()->subMinute()]);
        $this->app->instance(PublicAddressResolverInterface::class, new class implements PublicAddressResolverInterface {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        Http::fake(['retry.example/robots.txt' => Http::response('', 404), 'retry.example/' => Http::response('', 503)]);

        $result = app(LocalWebIndexIngestionService::class)->ingest(app(WebIndexRefreshSource::class), 1);
        $document = DB::table('web_index_documents')->where('normalized_domain', 'retry.example')->first();

        self::assertSame('partially_completed', $result['status']);
        self::assertSame('unavailable', $document->availability);
        self::assertGreaterThan(now()->addHours(5)->timestamp, strtotime((string) $document->next_refresh_at));
        self::assertSame(0, DB::table('web_index_documents')->whereNotNull('next_refresh_at')->where('next_refresh_at', '<=', now())->count());
    }

    public function test_permanent_unsafe_refresh_failure_is_not_scheduled_for_quick_retry(): void
    {
        app(LocalWebIndexIngestionService::class)->ingest($this->source([['canonical_url' => 'https://unsafe-retry.example/', 'organization_name' => 'Unsafe Retry']]), 1);
        DB::table('web_index_documents')->where('normalized_domain', 'unsafe-retry.example')->update(['next_refresh_at' => now()->subMinute()]);
        DB::table('web_index_documents')->where('normalized_domain', 'unsafe-retry.example')
            ->update(['canonical_url' => 'https://user:secret@unsafe-retry.example/']);

        app(LocalWebIndexIngestionService::class)->ingest(app(WebIndexRefreshSource::class), 1);
        $retryAt = strtotime((string) DB::table('web_index_documents')->where('normalized_domain', 'unsafe-retry.example')->value('next_refresh_at'));

        self::assertGreaterThan(now()->addDays(364)->timestamp, $retryAt);
    }

    public function test_changed_and_unchanged_refreshes_preserve_page_identity_and_content_change_time(): void
    {
        $service = app(LocalWebIndexIngestionService::class);
        $record = ['canonical_url' => 'https://fresh.example/', 'organization_name' => 'Fresh Company', 'visible_text_excerpt' => 'Opening soon', 'source_reference' => 'page:home'];
        $service->ingest($this->source([$record]), 5);
        $initial = DB::table('web_index_documents')->where('normalized_domain', 'fresh.example')->first();
        $service->ingest($this->source([$record]), 5);
        $unchanged = DB::table('web_index_documents')->where('id', $initial->id)->first();
        self::assertSame($initial->content_changed_at, $unchanged->content_changed_at);
        self::assertSame('available', $unchanged->availability);

        $this->travel(2)->seconds();
        $changeResult = $service->ingest($this->source([array_merge($record, ['visible_text_excerpt' => 'Now accepting customers'])]), 5);
        $changed = DB::table('web_index_documents')->where('id', $initial->id)->first();
        self::assertSame($initial->id, $changed->id);
        self::assertNotSame($initial->content_hash, $changed->content_hash);
        self::assertSame(1, $changeResult['updated'], json_encode($changeResult));
        self::assertNotSame($initial->content_changed_at, $changed->content_changed_at, "Initial: {$initial->content_changed_at}; changed: {$changed->content_changed_at}");
        self::assertSame('available', $changed->availability);
    }

    public function test_identity_page_retrieval_timestamp_does_not_make_unchanged_content_look_changed(): void
    {
        $service = app(LocalWebIndexIngestionService::class);
        $page = ['url' => 'https://page-evidence.example.com/contact', 'type' => 'contact', 'content_hash' => hash('sha256', 'same page'),
            'retrieved_at' => now()->toIso8601String(), 'identity' => ['telephone' => '+1 555 0100']];
        $record = ['canonical_url' => 'https://page-evidence.example.com/', 'organization_name' => 'Page Evidence Company',
            'structured_data' => ['entities' => []], 'identity_pages' => [$page]];
        $service->ingest($this->source([$record]), 1);
        $initial = DB::table('web_index_documents')->where('normalized_domain', 'page-evidence.example.com')->first();
        $this->travel(2)->seconds();
        $page['retrieved_at'] = now()->toIso8601String();
        $result = $service->ingest($this->source([array_merge($record, ['identity_pages' => [$page]])]), 1);
        $refreshed = DB::table('web_index_documents')->where('id', $initial->id)->first();

        self::assertSame(1, $result['unchanged']);
        self::assertSame($initial->content_hash, $refreshed->content_hash);
        self::assertSame($page['retrieved_at'], json_decode((string) $refreshed->structured_data, true)['identity_pages'][0]['retrieved_at']);
    }

    private function source(array $rows, string $name = 'test_public_source', array $metrics = []): WebIndexIngestionSourceInterface
    {
        return new class($rows, $name, $metrics) implements WebIndexIngestionSourceInterface {
            public function __construct(private array $rows, private string $sourceName, private array $sourceMetrics) {}
            public function name(): string { return $this->sourceName; }
            public function documents(int $limit, array $options = [], ?array $cursor = null): iterable
            {
                foreach ($this->rows as $index => $row) {
                    $position = (int) ($row['_cursor']['position'] ?? ($index + 1));
                    if ($cursor && $position <= (int) ($cursor['position'] ?? 0)) continue;
                    yield $row;
                }
            }
            public function metrics(): array { return ['source_candidates' => count($this->rows), ...$this->sourceMetrics]; }
        };
    }
}
