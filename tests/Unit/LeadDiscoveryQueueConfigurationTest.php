<?php

namespace Tests\Unit;

use App\Jobs\DiscoverLeadSourcePageJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Tests\TestCase;

class LeadDiscoveryQueueConfigurationTest extends TestCase
{
    public function test_live_page_jobs_release_uniqueness_before_processing_and_use_bounded_retries(): void
    {
        self::assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new DiscoverLeadSourcePageJob('tenant-id', 'checkpoint-id'));
        self::assertSame(3, (new DiscoverLeadSourcePageJob('tenant-id', 'checkpoint-id'))->tries);
        self::assertSame(3, config('horizon.environments.production.supervisor-lead-discovery.tries'));
        self::assertSame(3, config('horizon.environments.local.supervisor-lead-discovery.tries'));
    }

    public function test_live_worker_concurrency_is_configurable_within_the_safe_one_to_two_cap(): void
    {
        $configuredConcurrency = (int) config('lead_discovery.live_worker_concurrency');

        self::assertGreaterThanOrEqual(1, $configuredConcurrency);
        self::assertLessThanOrEqual(2, $configuredConcurrency);
        self::assertSame($configuredConcurrency, config('horizon.environments.production.supervisor-lead-discovery.processes'));
        self::assertSame($configuredConcurrency, config('horizon.environments.local.supervisor-lead-discovery.processes'));
    }
}
