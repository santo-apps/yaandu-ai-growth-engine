<?php

namespace App\WebsiteResolution;

use InvalidArgumentException;

final class WebIndexIngestionSourceRegistry
{
    /** @return array<string, WebIndexIngestionSourceInterface> */
    public function all(): array
    {
        $sources = [
            app(VerifiedDiscoveryIndexIngestionSource::class),
            app(OpenStreetMapWebsiteIndexIngestionSource::class),
            app(WikidataWebsiteIndexIngestionSource::class),
            app(CommonCrawlIngestionSource::class),
            app(CommonCrawlOfflineArtifactSource::class),
            app(WebIndexRefreshSource::class),
        ];

        $registry = [];
        foreach ($sources as $source) $registry[$source->name()] = $source;

        return $registry;
    }

    public function get(string $name): WebIndexIngestionSourceInterface
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException('The web index ingestion source is not enabled.');
    }
}
