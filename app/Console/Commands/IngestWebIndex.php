<?php

namespace App\Console\Commands;

use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\WebIndexIngestionSourceRegistry;
use App\Jobs\RunWebIndexIngestionJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class IngestWebIndex extends Command
{
    protected $signature = 'web-index:ingest {--source= : Required registered source}
        {--limit= : Required maximum document/domain count (1-500)}
        {--location= : Configured OSM city/region}
        {--country= : Country label for the configured OSM location}
        {--artifact= : Path to an already-downloaded local Common Crawl .warc.gz artifact}
        {--crawl-id= : Common Crawl snapshot identifier for offline artifact provenance}
        {--category= : Configured OSM category, or comma-separated categories}
        {--max-fetches= : Explicit maximum source website fetches}
        {--max-bytes= : Explicit maximum run bytes}
        {--max-runtime= : Explicit maximum run duration in seconds}
        {--max-failures= : Explicit maximum document/source failures}
        {--max-source-records= : Offline Common Crawl maximum records to scan}
        {--max-artifact-bytes= : Offline Common Crawl maximum staged artifact bytes}
        {--max-document-bytes= : Offline Common Crawl maximum page bytes}
        {--resume= : Resume a paused, partial, or interrupted run ID}
        {--queue : Dispatch to the isolated Redis/Horizon web-index queue}
        {--dry-run : Preview the bounded plan without source requests or writes}';

    protected $description = 'Run a bounded, resumable public web-index ingestion source.';

    public function handle(WebIndexIngestionSourceRegistry $sources, LocalWebIndexIngestionService $ingestion): int
    {
        $name = trim((string) $this->option('source'));
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($name === '' || $limit === false || $limit < 1 || $limit > 500) {
            $this->error('Specify a registered --source and explicit --limit from 1 to 500.');
            return self::INVALID;
        }
        try { $source = $sources->get($name); }
        catch (Throwable) { $this->error('The requested ingestion source is not registered.'); return self::INVALID; }

        $categories = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('category')))));
        $location = trim((string) $this->option('location'));
        $locations = (array) config('discovery.locations', []);
        if ($name === 'osm_public_websites') {
            if ($location === '' || ! isset($locations[$location])) {
                $this->error('OSM ingestion requires a configured --location.');
                return self::INVALID;
            }
            if ($categories === []) $categories = ['business'];
            foreach ($categories as $category) if (! array_key_exists(mb_strtolower($category), (array) config('discovery.osm_categories', []))) {
                $this->error('Every --category must be configured in the OSM category map.'); return self::INVALID;
            }
        }
        if ($name === 'common_crawl_offline_artifact') {
            $artifact = (string) $this->option('artifact');
            if ($artifact === '' || ! is_file($artifact) || ! str_ends_with(strtolower($artifact), '.warc.gz')) {
                $this->error('Offline Common Crawl processing requires --artifact pointing to a staged .warc.gz file.'); return self::INVALID;
            }
        }
        try { $options = array_filter([
            'location' => $location ?: null, 'country' => trim((string) $this->option('country')) ?: ($locations[$location]['country'] ?? null),
            'categories' => $categories ?: null, 'max_source_records' => $limit,
            'max_fetches' => $this->boundedOption('max-fetches', 250, 1, 1000),
            'max_bytes' => $this->boundedOption('max-bytes', (int) config('website_resolution.index_ingestion_max_bytes', 250_000_000), 1024, 1_000_000_000),
            'max_runtime_seconds' => $this->boundedOption('max-runtime', (int) config('website_resolution.index_ingestion_max_duration_seconds', 300), 10, 3600),
            'max_failures' => $this->boundedOption('max-failures', (int) config('website_resolution.index_ingestion_max_failures', 50), 1, 500),
            'max_domains' => $limit,
            'artifact_path' => $name === 'common_crawl_offline_artifact' ? realpath((string) $this->option('artifact')) : null,
            'crawl_id' => $name === 'common_crawl_offline_artifact' ? (trim((string) $this->option('crawl-id')) ?: 'unspecified') : null,
            'max_records' => $name === 'common_crawl_offline_artifact' ? $this->boundedOption('max-source-records', 5000, 1, 100000) : null,
            'max_artifact_bytes' => $name === 'common_crawl_offline_artifact' ? $this->boundedOption('max-artifact-bytes', 50_000_000, 1024, 100_000_000) : null,
            'max_document_bytes' => $name === 'common_crawl_offline_artifact' ? $this->boundedOption('max-document-bytes', 250_000, 1024, 1_000_000) : null,
        ], fn ($value) => $value !== null && $value !== ''); }
        catch (\InvalidArgumentException $error) { $this->error($error->getMessage()); return self::INVALID; }

        if ($this->option('dry-run')) {
            $estimate = match ($name) {
                'verified_discovery' => DB::table('discovery_candidates')->where('verification_state', 'verified')->whereNotNull('normalized_domain')->count(),
                'common_crawl_known_domains' => DB::table('web_index_documents')->where('document_type', 'business')->distinct('normalized_domain')->count('normalized_domain'),
                'common_crawl_offline_artifact' => 'staged local artifact; no download',
                default => null,
            };
            $this->table(['source', 'location', 'categories', 'limit', 'fetch budget', 'byte budget', 'runtime seconds', 'candidate estimate'], [[
                $name, $location ?: 'n/a', implode(', ', $categories) ?: 'n/a', $limit, $options['max_fetches'], $options['max_bytes'], $options['max_runtime_seconds'], $estimate ?? 'not queried in dry-run',
            ]]);
            $this->line('Dry run performs no internet requests and writes no ingestion run.');
            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            try {
                if ($this->option('resume')) {
                    $runId = $ingestion->prepareQueuedResume($source, (string) $this->option('resume'), $limit, $this->resumeOptions($options, $limit));
                } else {
                    $runId = $ingestion->createRun($source, $limit, $options);
                }
            } catch (Throwable) { $this->error('The bounded ingestion run is not eligible for queue resume.'); return self::INVALID; }
            RunWebIndexIngestionJob::dispatch($runId);
            $this->info(($this->option('resume') ? 'Requeued resumable' : 'Queued bounded').' web-index run '.$runId.'.');
            return self::SUCCESS;
        }

        try {
            $runId = $this->option('resume') ?: null;
            $result = $ingestion->ingest($source, $limit, $runId ? $this->resumeOptions($options, $limit) : $options, $runId);
        } catch (Throwable) {
            $this->error('Index ingestion failed or paused safely; the run ID and checkpoint are persisted.');
            return self::FAILURE;
        }
        $this->table(['run', 'source', 'status', 'processed', 'new docs', 'changed', 'unchanged', 'new domains', 'duplicates', 'failed', 'bytes'], [[
            $result['run_id'], $result['source'], $result['status'], $result['processed'], $result['inserted'], $result['updated'], $result['unchanged'],
            $result['unique_domains_added'], $result['duplicates'], $result['failed'], $result['bytes_processed'],
        ]]);
        if ($result['source_metrics'] !== []) $this->line('Metrics: '.json_encode($result['source_metrics']));
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function boundedOption(string $name, int $default, int $min, int $max): int
    {
        $raw = $this->option($name);
        $value = $raw === null ? $default : filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) throw new \InvalidArgumentException("--{$name} is outside the allowed range.");
        return $value;
    }

    private function resumeOptions(array $options, int $limit): array
    {
        $resumable = ['max_source_records' => $limit, 'max_domains' => $limit];
        if ($this->option('location')) $resumable['location'] = $options['location'];
        if ($this->option('country')) $resumable['country'] = $options['country'];
        if ($this->option('category')) $resumable['categories'] = $options['categories'];
        foreach (['max-fetches' => 'max_fetches', 'max-bytes' => 'max_bytes', 'max-runtime' => 'max_runtime_seconds', 'max-failures' => 'max_failures',
            'max-source-records' => 'max_records', 'max-artifact-bytes' => 'max_artifact_bytes', 'max-document-bytes' => 'max_document_bytes'] as $input => $key) {
            if ($this->option($input) !== null) $resumable[$key] = $options[$key];
        }
        return $resumable;
    }
}
