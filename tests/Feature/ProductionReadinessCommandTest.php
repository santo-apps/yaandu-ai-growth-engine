<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Tests\TestCase;

final class ProductionReadinessCommandTest extends TestCase
{
    public function test_readiness_is_read_only_and_never_prints_provider_credentials(): void
    {
        config(['app.env' => 'testing', 'app.debug' => true, 'app.key' => 'base64:local-test-key', 'queue.default' => 'sync',
            'filesystems.default' => 'local', 'app.url' => 'http://localhost', 'mail.default' => 'log',
            'ai.providers.openai.key' => 'do-not-print-this-secret']);
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException('redis unavailable'));
        $this->app->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, function ($mock): void {
            $mock->shouldReceive('all')->andReturn([]);
        }));
        $this->app->instance(Schedule::class, new Schedule());

        $this->artisan('production:readiness')
            ->expectsOutputToContain('APP_ENV')
            ->expectsOutputToContain('APP_DEBUG')
            ->assertExitCode(1);
        self::assertStringNotContainsString('do-not-print-this-secret', Artisan::output());
    }

    public function test_api_exceptions_do_not_expose_internal_details_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/api/test-safe-error', fn () => throw new \RuntimeException('/private/path/provider-token-secret'));

        $response = $this->getJson('/api/test-safe-error');

        $response->assertStatus(500);
        $response->assertDontSee('/private/path/provider-token-secret');
    }

    public function test_production_never_launches_playwright_from_the_laravel_worker(): void
    {
        $this->app['env'] = 'production';
        config(['crawling.playwright.enabled' => true, 'crawling.screenshots.enabled' => true]);
        $result = (new \App\WebsiteIntelligence\PlaywrightScreenshotService(app(\App\Crawling\UrlPolicy::class)))
            ->capture('tenant-id', 'scan-id', 'page-id', 'ftp://localhost');

        self::assertNull($result);
    }

    public function test_readiness_fails_when_debug_environment_flag_is_enabled_even_if_runtime_is_forced_safe(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'production_readiness.debug_enabled' => true,
            'app.key' => 'base64:local-test-key', 'queue.default' => 'redis']);
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn('PONG');
        $this->app->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, function ($mock): void {
            $mock->shouldReceive('all')->andReturn([]);
        }));

        $this->artisan('production:readiness')->expectsOutputToContain('Debug must be disabled')->assertExitCode(1);
    }
}
