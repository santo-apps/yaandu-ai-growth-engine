<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Tests\TestCase;

final class ProductionReadinessCommandTest extends TestCase
{
    public function test_readiness_reports_gates_and_never_prints_provider_credentials(): void
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

    public function test_verified_private_local_storage_passes_and_backups_remain_a_separate_gate(): void
    {
        $privatePath = $this->useTemporaryPrivateStorage();
        $this->prepareStorageReadiness(true, true);

        try {
            $output = $this->runReadiness();

            $this->assertStorageStatus($output, 'PASS');
            self::assertStringContainsString('Backups', $output);
            self::assertMatchesRegularExpression('/\|\s*Backups\s*\|\s*PASS\s*\|/', $output);
            self::assertDirectoryExists($privatePath);
            self::assertSame([], File::files($privatePath), 'The temporary runtime probe must be deleted.');
        } finally {
            File::deleteDirectory(dirname(dirname(dirname($privatePath))));
        }
    }

    public function test_local_storage_pass_does_not_waive_the_mandatory_backup_gate(): void
    {
        $privatePath = $this->useTemporaryPrivateStorage();
        $this->prepareStorageReadiness(true, false);

        try {
            $output = $this->runReadiness();

            $this->assertStorageStatus($output, 'PASS');
            self::assertMatchesRegularExpression('/\|\s*Backups\s*\|\s*FAIL\s*\|/', $output);
        } finally {
            File::deleteDirectory(dirname(dirname(dirname($privatePath))));
        }
    }

    public function test_local_storage_fails_when_private_directory_is_not_writable(): void
    {
        $privatePath = $this->useTemporaryPrivateStorage();
        chmod($privatePath, 0500);
        $this->prepareStorageReadiness(true, true);

        try {
            $output = $this->runReadiness();
            $this->assertStorageStatus($output, 'FAIL');
            self::assertStringContainsString('not writable by the application', $output);
        } finally {
            chmod($privatePath, 0700);
            File::deleteDirectory(dirname(dirname(dirname($privatePath))));
        }
    }

    public function test_local_storage_fails_without_runtime_verification_attestation(): void
    {
        $privatePath = $this->useTemporaryPrivateStorage();
        $this->prepareStorageReadiness(false, true);

        try {
            $output = $this->runReadiness();

            $this->assertStorageStatus($output, 'FAIL');
            self::assertStringContainsString('PRODUCTION_STORAGE_RUNTIME_VERIFIED', $output);
            self::assertSame([], File::files($privatePath), 'The temporary runtime probe must be deleted.');
        } finally {
            File::deleteDirectory(dirname(dirname(dirname($privatePath))));
        }
    }

    public function test_s3_remains_supported_with_existing_configuration_and_attestation(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['filesystems.default' => 's3', 'filesystems.disks.s3.bucket' => 'private-test-bucket',
            'filesystems.disks.s3.key' => 'test-key', 'filesystems.disks.s3.secret' => 'test-secret']);

        $output = $this->runReadiness();

        $this->assertStorageStatus($output, 'PASS');
        self::assertStringContainsString('deployment owner attests read/write/delete and private bucket verification', $output);
        self::assertStringNotContainsString('test-secret', $output);
    }

    public function test_local_storage_resolving_under_public_web_root_is_rejected(): void
    {
        $this->prepareStorageReadiness(true, true);
        $publicPath = public_path('.production-storage-test-'.Str::uuid());
        $privatePath = $publicPath.'/app/private';
        File::ensureDirectoryExists($privatePath, 0700, true);
        $this->app->useStoragePath($publicPath);
        config(['filesystems.disks.local.root' => $privatePath]);
        Storage::forgetDisk('local');

        try {
            $output = $this->runReadiness();
            $this->assertStorageStatus($output, 'FAIL');
            self::assertStringContainsString('inside the public web root', $output);
        } finally {
            File::deleteDirectory($publicPath);
        }
    }

    public function test_disabled_mail_passes_for_controlled_pilot_without_assuming_delivery(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['production_readiness.mail_enabled' => false, 'mail.default' => 'log']);

        $output = $this->runReadiness();

        $this->assertMailStatus($output, 'PASS');
        self::assertStringContainsString('Outbound mail is intentionally disabled for the controlled pilot.', $output);
        self::assertStringNotContainsString('provider delivery confirmed', $output);
    }

    public function test_enabled_log_mailer_fails_readiness(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['production_readiness.mail_enabled' => true, 'mail.default' => 'log',
            'mail.mailers.log' => ['transport' => 'log', 'channel' => null]]);

        $this->assertMailStatus($this->runReadiness(), 'FAIL');
    }

    public function test_enabled_array_mailer_fails_readiness(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['production_readiness.mail_enabled' => true, 'mail.default' => 'array',
            'mail.mailers.array' => ['transport' => 'array']]);

        $this->assertMailStatus($this->runReadiness(), 'FAIL');
    }

    public function test_enabled_real_configured_smtp_transport_passes_without_sending_mail(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['production_readiness.mail_enabled' => true, 'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587]]);

        $output = $this->runReadiness();

        $this->assertMailStatus($output, 'PASS');
        self::assertStringContainsString('Confirm provider delivery before enabling notifications; no delivery is assumed.', $output);
    }

    public function test_enabled_smtp_transport_without_host_or_valid_port_fails_readiness(): void
    {
        $this->prepareStorageReadiness(true, true);
        config(['production_readiness.mail_enabled' => true, 'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '', 'port' => 0]]);

        $this->assertMailStatus($this->runReadiness(), 'FAIL');
    }

    private function useTemporaryPrivateStorage(): string
    {
        $storageRoot = sys_get_temp_dir().'/yaandu-storage-readiness-'.Str::uuid().'/storage';
        $privatePath = $storageRoot.'/app/private';
        File::ensureDirectoryExists($privatePath, 0700, true);
        $this->app->useStoragePath($storageRoot);
        config(['filesystems.default' => 'local', 'filesystems.disks.local.root' => $privatePath]);
        Storage::forgetDisk('local');

        return $privatePath;
    }

    private function prepareStorageReadiness(bool $runtimeVerified, bool $backupVerified): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.key' => 'base64:local-test-key',
            'queue.default' => 'redis', 'production_readiness.debug_enabled' => false,
            'production_readiness.storage_runtime_verified' => $runtimeVerified,
            'production_readiness.backup_verified' => $backupVerified,
            'filesystems.default' => 'local']);
        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        $this->app->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, function ($mock): void {
            $mock->shouldReceive('all')->andReturn([]);
        }));
        $this->app->instance(Schedule::class, new Schedule());
    }

    private function runReadiness(): string
    {
        Artisan::call('production:readiness');

        return Artisan::output();
    }

    private function assertStorageStatus(string $output, string $status): void
    {
        self::assertMatchesRegularExpression('/\|\s*Production storage\s*\|\s*'.preg_quote($status, '/').'\s*\|/', $output, $output);
    }

    private function assertMailStatus(string $output, string $status): void
    {
        self::assertMatchesRegularExpression('/\|\s*Mail\s*\|\s*'.preg_quote($status, '/').'\s*\|/', $output, $output);
    }
}
