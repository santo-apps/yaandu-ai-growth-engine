<?php

namespace App\Discovery;

/** Test adapter only; it never performs network requests. */
final class DeterministicSearchEngine implements SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResultCollection
    {
        return new SearchResultCollection(array_slice((array) config('discovery.web_search_fixtures', []), 0, $query->limit));
    }
}
