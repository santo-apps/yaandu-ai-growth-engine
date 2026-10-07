<?php

namespace App\Discovery;

interface DeterministicCandidateFixtureSourceInterface
{
    /** @return array{status:int,html?:string,response_time_ms?:int}|null */
    public function fixtureForCandidate(string $sourceReference): ?array;
}
