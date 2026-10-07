<?php

namespace App\Console\Commands;

use App\WebsiteResolution\WikidataWebsiteIndexIngestionSource;
use Illuminate\Console\Command;
use Throwable;

final class ReportWikidataWebsiteSeeds extends Command
{
    protected $signature = 'web-index:wikidata-seed-report {--limit=25 : Maximum linked Wikidata entities to inspect (1-100)}';
    protected $description = 'Measure bounded P856 website seed yield without fetching websites or writing corpus records.';

    public function handle(WikidataWebsiteIndexIngestionSource $source): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Specify an explicit --limit from 1 to 100.');
            return self::INVALID;
        }
        try { $metrics = $source->inspectSeeds($limit); }
        catch (Throwable) { $this->error('Wikidata seed inspection failed safely; no websites were fetched and no documents written.'); return self::FAILURE; }
        $this->table(['Measure', 'Count'], array_map(static fn ($key, $value) => [$key, is_scalar($value) ? $value : json_encode($value)], array_keys($metrics), array_values($metrics)));
        $this->line('Bounded linked-entity preview only: P856 values were normalized but target pages were not fetched.');
        return self::SUCCESS;
    }
}
