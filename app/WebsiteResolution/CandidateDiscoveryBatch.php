<?php

namespace App\WebsiteResolution;

final readonly class CandidateDiscoveryBatch
{
    /**
     * @param list<array{query:string,result_url:?string,target_url:?string,title:?string,snippet:?string,rank:?int,source_reference:?string,result_type?:string,metadata?:array,evidence?:array}> $results
     * @param list<string> $queries
     */
    public function __construct(public array $results = [], public array $queries = [], public array $metrics = [], public array $failures = []) {}
}
