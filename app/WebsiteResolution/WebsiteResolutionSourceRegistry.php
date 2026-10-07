<?php

namespace App\WebsiteResolution;

use InvalidArgumentException;

final class WebsiteResolutionSourceRegistry
{
    public function all(): array
    {
        $sources = [app(OSMWebsiteEvidenceSource::class), app(WikidataWebsiteResolutionSource::class), app(CommonCrawlResolutionSource::class), app(LocalWebIndexResolutionSource::class)];
        if (app()->environment('testing')) $sources[] = app(DeterministicWebsiteResolutionSource::class);
        return $sources;
    }

    public function get(string $name): WebsiteResolutionSourceInterface
    {
        foreach ($this->all() as $source) if ($source->name() === $name) return $source;
        throw new InvalidArgumentException('The website-resolution source is not enabled.');
    }
}
