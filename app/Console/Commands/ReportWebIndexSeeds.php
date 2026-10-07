<?php

namespace App\Console\Commands;

use App\WebsiteResolution\OpenStreetMapWebsiteIndexIngestionSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportWebIndexSeeds extends Command
{
    protected $signature = 'web-index:seed-report {--locations= : Comma-separated configured locations (required)}
        {--categories= : Comma-separated configured OSM categories (required)}
        {--per-query=50 : Maximum business records per location/category query (1-100)}';

    protected $description = 'Measure bounded OSM public website seed yield without fetching websites or writing corpus records.';

    public function handle(OpenStreetMapWebsiteIndexIngestionSource $osm): int
    {
        $locations = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('locations')))));
        $categories = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('categories'))))));
        $limit = filter_var($this->option('per-query'), FILTER_VALIDATE_INT);
        $configuredLocations = (array) config('discovery.locations', []);
        $configuredCategories = (array) config('discovery.osm_categories', []);
        if ($locations === [] || $categories === [] || count($locations) * count($categories) > 20
            || $limit === false || $limit < 1 || $limit > 100) {
            $this->error('Provide configured --locations and --categories, at most 20 query combinations, and --per-query from 1 to 100.');
            return self::INVALID;
        }
        foreach ($locations as $location) if (! isset($configuredLocations[$location])) {
            $this->error('Every location must be configured.');
            return self::INVALID;
        }
        foreach ($categories as $category) if (! isset($configuredCategories[mb_strtolower($category)])) {
            $this->error('Every category must be configured.');
            return self::INVALID;
        }

        $rows = [];
        foreach ($locations as $location) foreach ($categories as $category) {
            $country = (string) ($configuredLocations[$location]['country'] ?? '');
            try {
                $result = $osm->inspectSeeds($location, $country, $category, $limit);
                $corpusDuplicates = DB::table('web_index_documents')
                    ->whereIn('normalized_domain', $result['usable_domains'] ?? [])
                    ->distinct('normalized_domain')->count('normalized_domain');
                $rows[] = [$location, $category, $result['business_records'], $result['website_refs'], $result['contact_website_refs'],
                    $result['brand_website_refs'], $result['operator_website_refs'], $result['normalized_urls'],
                    $result['usable_normalized_domains'], $result['website_domain_yield_percent'].'%', $result['duplicate_urls'] + $result['duplicate_domains'], $corpusDuplicates];
            } catch (Throwable) {
                $rows[] = [$location, $category, 0, 0, 0, 0, 0, 0, 0, 'query failed', 'source failure', 'n/a'];
            }
        }
        $this->table(['Location', 'Category', 'Businesses', 'website', 'contact:website', 'brand:website', 'operator:website',
            'Normalized URLs', 'Unique usable domains', 'Domain yield', 'Source duplicates', 'Corpus duplicates'], $rows);
        $this->line('Preview only: no website fetches and no index writes. Domain yield is before URL/DNS/robots validation.');
        return self::SUCCESS;
    }
}
