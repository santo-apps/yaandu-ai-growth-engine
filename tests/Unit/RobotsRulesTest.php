<?php

namespace Tests\Unit;

use App\Crawling\RobotsRules;
use PHPUnit\Framework\TestCase;

class RobotsRulesTest extends TestCase
{
    public function test_disallowed_path_is_rejected(): void
    {
        self::assertFalse((new RobotsRules())->allows("User-agent: *\nDisallow: /private", '/private/team'));
    }

    public function test_non_disallowed_path_is_allowed(): void
    {
        self::assertTrue((new RobotsRules())->allows("User-agent: *\nDisallow: /private", '/about'));
    }
}
