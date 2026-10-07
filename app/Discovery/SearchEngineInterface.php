<?php

namespace App\Discovery;

interface SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResultCollection;
}
