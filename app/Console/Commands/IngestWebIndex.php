<?php

namespace App\Console\Commands;

use App\WebsiteResolution\LocalWebIndexIngestionService;
use App\WebsiteResolution\OpenStreetMapWebsiteIndexIngestionSource;
use App\WebsiteResolution\VerifiedDiscoveryIndexIngestionSource;
use Illuminate\Console\Command;
use Throwable;

final class IngestWebIndex extends Command
{
    protected $signature = 'web-index:ingest {--source= : Required source (verified_discovery or osm_public_websites)} {--limit= : Required maximum documents (1-500; OSM source is capped at 50)}';
    protected $description = 'Safely ingest a bounded set of public website documents into the local website index.';

    public function handle(LocalWebIndexIngestionService $ingestion, VerifiedDiscoveryIndexIngestionSource $verifiedDiscovery, OpenStreetMapWebsiteIndexIngestionSource $osmWebsites): int
    {
        $sourceName = (string) $this->option('source');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! in_array($sourceName, ['verified_discovery', 'osm_public_websites'], true) || $limit === false || $limit < 1 || $limit > 500
            || ($sourceName === 'osm_public_websites' && $limit > 50)) {
            $this->error('Specify an allowed --source and explicit --limit between 1 and 500 (maximum 50 for osm_public_websites).');
            return self::INVALID;
        }
        try {
            $result = $ingestion->ingest($sourceName === 'verified_discovery' ? $verifiedDiscovery : $osmWebsites, $limit);
        } catch (Throwable) {
            $this->error('Index ingestion failed; details were omitted to avoid exposing source data.');
            return self::FAILURE;
        }
        $this->table(['source', 'processed', 'inserted', 'updated', 'unchanged', 'document failures', 'source failures'], [[
            $result['source'], $result['processed'], $result['inserted'], $result['updated'], $result['unchanged'], $result['failed'], $result['source_failures'],
        ]]);
        if ($result['source_metrics'] !== []) $this->line('Source metrics: '.json_encode($result['source_metrics']));
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
