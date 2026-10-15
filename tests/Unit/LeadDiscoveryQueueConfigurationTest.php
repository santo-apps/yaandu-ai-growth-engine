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

    public function test_live_discovery_worker_concurrency_is_serialized_for_provider_pacing(): void
    {
        self::assertSame(1, config('horizon.environments.production.supervisor-lead-discovery.processes'));
        self::assertSame(1, config('horizon.environments.local.supervisor-lead-discovery.processes'));
        self::assertSame(45, config('horizon.environments.production.supervisor-lead-discovery.timeout'));
        self::assertSame(45, (new DiscoverLeadSourcePageJob('tenant-id', 'checkpoint-id'))->timeout);
        self::assertSame(5, config('lead_discovery.osm_min_request_interval_seconds'));
    }
}
